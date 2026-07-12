<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Service\Personio;

use App\DTO\Personio\AbsencePeriod;
use App\DTO\Personio\AbsenceType;
use App\DTO\Personio\AttendancePeriod;
use App\Exception\Personio\PersonioApiException;
use DateTimeImmutable;
use DateTimeInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use SensitiveParameter;

use function ctype_digit;
use function is_array;
use function is_int;
use function is_object;
use function is_string;
use function json_decode;
use function max;
use function parse_str;
use function parse_url;
use function rawurlencode;
use function rtrim;
use function sprintf;
use function time;

use const JSON_THROW_ON_ERROR;
use const PHP_URL_QUERY;

/**
 * Personio API v2 client (ADR-024).
 *
 * Authenticates with the company-level `client_credentials` grant — Personio
 * offers no per-employee delegation — and covers the attendance-period CRUD
 * plus the persons listing (for later employee auto-matching). The bearer
 * token is cached in-instance and refreshed before expiry; the client backs
 * off on HTTP 429.
 *
 * One instance is bound to one {@see \App\Entity\PersonioConfig} with its
 * decrypted client secret ({@see PersonioClientFactory}).
 */
class PersonioClient
{
    /** Refresh the token this many seconds before its recorded expiry. */
    private const int EXPIRY_SKEW_SECONDS = 60;

    /** Total attempts (initial + retries) for a 429-throttled request. */
    private const int MAX_ATTEMPTS = 3;

    /** Page size requested from the cursor-paginated list endpoints. */
    private const int PAGE_SIZE = 200;

    /** The absence endpoints cap `limit` at 100 (attendance/persons allow 200). */
    private const int ABSENCE_PAGE_SIZE = 100;

    /**
     * Personio absence timestamps are timezone-less (`DateTimeWithoutTimezone`,
     * e.g. `2026-07-06T00:00:00.000`); the date-range filter takes the same shape.
     */
    private const string ABSENCE_DATE_FORMAT = 'Y-m-d\TH:i:s.v';

    private ?Client $client = null;

    private ?string $accessToken = null;

    private int $tokenExpiresAt = 0;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $clientId,
        #[SensitiveParameter]
        private readonly string $clientSecret,
    ) {
    }

    /**
     * Lists the person's attendance periods whose start falls within the window.
     *
     * @throws PersonioApiException
     *
     * @return list<AttendancePeriod>
     */
    public function listAttendancePeriods(string $personId, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return $this->paginate(
            'v2/attendance-periods',
            [
                'person.id' => $personId,
                'start.date_time.gte' => $from->format(DateTimeInterface::ATOM),
                'start.date_time.lte' => $to->format(DateTimeInterface::ATOM),
                'limit' => self::PAGE_SIZE,
            ],
            static fn (object $item): AttendancePeriod => AttendancePeriod::fromApiResponse($item),
        );
    }

    /**
     * Lists the person's absence periods whose start falls within the window
     * (ADR-024 §4). Filtered on `starts_from` — mirrors the attendance window.
     *
     * @throws PersonioApiException
     *
     * @return list<AbsencePeriod>
     */
    public function listAbsencePeriods(string $personId, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return $this->paginate(
            'v2/absence-periods',
            [
                'person.id' => $personId,
                'starts_from.date_time.gte' => $from->format(self::ABSENCE_DATE_FORMAT),
                'starts_from.date_time.lte' => $to->format(self::ABSENCE_DATE_FORMAT),
                'limit' => self::ABSENCE_PAGE_SIZE,
            ],
            static fn (object $item): AbsencePeriod => AbsencePeriod::fromApiResponse($item),
        );
    }

    /**
     * Lists all absence types (id -> name/unit), the lookup that resolves a
     * period's type to a TT activity by name (ADR-024 §4).
     *
     * @throws PersonioApiException
     *
     * @return list<AbsenceType>
     */
    public function listAbsenceTypes(): array
    {
        return $this->paginate(
            'v2/absence-types',
            ['limit' => self::ABSENCE_PAGE_SIZE],
            static fn (object $item): AbsenceType => AbsenceType::fromApiResponse($item),
        );
    }

    /**
     * Creates a WORK attendance period and returns its Personio id.
     *
     * @throws PersonioApiException
     */
    public function createAttendancePeriod(string $personId, string $type, string $startIso, string $endIso, ?string $comment = null): string
    {
        $body = [
            'person' => ['id' => $personId],
            'type' => $type,
            'start' => ['date_time' => $startIso],
            'end' => ['date_time' => $endIso],
        ];
        if (null !== $comment) {
            $body['comment'] = $comment;
        }

        $decoded = $this->decodeObject($this->send('POST', 'v2/attendance-periods', ['json' => $body], true));

        return $this->extractId($decoded);
    }

    /**
     * @throws PersonioApiException
     */
    public function updateAttendancePeriod(string $periodId, string $startIso, string $endIso, ?string $comment = null): void
    {
        $body = [
            'start' => ['date_time' => $startIso],
            'end' => ['date_time' => $endIso],
        ];
        if (null !== $comment) {
            $body['comment'] = $comment;
        }

        $this->send('PATCH', 'v2/attendance-periods/' . rawurlencode($periodId), ['json' => $body], true);
    }

    /**
     * @throws PersonioApiException
     */
    public function deleteAttendancePeriod(string $periodId): void
    {
        $this->send('DELETE', 'v2/attendance-periods/' . rawurlencode($periodId), [], true);
    }

    /**
     * Lists all Personio persons (for later employee-id auto-matching).
     *
     * @throws PersonioApiException
     *
     * @return list<array{id: string, first_name: ?string, last_name: ?string, email: ?string}>
     */
    public function listPersons(): array
    {
        return $this->paginate(
            'v2/persons',
            ['limit' => self::PAGE_SIZE],
            fn (object $item): array => $this->mapPerson($item),
        );
    }

    /**
     * Test seam: every Guzzle client is created here.
     *
     * @param array<string, mixed> $config
     */
    protected function createHttpClient(array $config): Client
    {
        return new Client($config);
    }

    /**
     * Sleeps between throttled retries (seam so tests do not wait).
     */
    protected function delay(int $seconds): void
    {
        sleep($seconds);
    }

    /**
     * Walks a cursor-paginated list endpoint, mapping each item.
     *
     * @template T
     *
     * @param array<string, scalar> $query
     * @param callable(object): T   $mapper
     *
     * @throws PersonioApiException
     *
     * @return list<T>
     */
    private function paginate(string $path, array $query, callable $mapper): array
    {
        $items = [];
        $cursor = null;

        do {
            $pageQuery = $query;
            if (null !== $cursor) {
                $pageQuery['cursor'] = $cursor;
            }

            $decoded = $this->decodeObject($this->send('GET', $path, ['query' => $pageQuery], true));
            foreach ($this->dataList($decoded) as $item) {
                $items[] = $mapper($item);
            }

            $cursor = $this->nextCursor($decoded);
        } while (null !== $cursor);

        return $items;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @throws PersonioApiException
     */
    private function send(string $method, string $path, array $options, bool $authenticated): ResponseInterface
    {
        if ($authenticated) {
            $headers = $options['headers'] ?? [];
            if (!is_array($headers)) {
                $headers = [];
            }
            $headers['Authorization'] = 'Bearer ' . $this->getAccessToken();
            $options['headers'] = $headers;
        }
        $options['http_errors'] = false;

        $attempt = 0;
        while (true) {
            ++$attempt;
            try {
                $response = $this->httpClient()->request($method, $path, $options);
            } catch (GuzzleException $guzzleException) {
                throw new PersonioApiException('Personio API request failed: ' . $guzzleException->getMessage(), 0, $guzzleException);
            }

            $status = $response->getStatusCode();
            if (429 === $status && $attempt < self::MAX_ATTEMPTS) {
                $this->delay($this->retryAfterSeconds($response));

                continue;
            }

            if ($status < 200 || $status >= 300) {
                throw new PersonioApiException(sprintf('Personio API %s %s failed (HTTP %d): %s', $method, $path, $status, (string) $response->getBody()), $status);
            }

            return $response;
        }
    }

    /**
     * Returns a cached, non-expired bearer token, fetching a new one when needed.
     *
     * @throws PersonioApiException
     */
    private function getAccessToken(): string
    {
        if (null !== $this->accessToken && time() < $this->tokenExpiresAt - self::EXPIRY_SKEW_SECONDS) {
            return $this->accessToken;
        }

        $decoded = $this->decodeObject($this->send('POST', 'v2/auth/token', [
            'form_params' => [
                'grant_type' => 'client_credentials',
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
            ],
        ], false));

        $token = $decoded->access_token ?? null;
        $expiresIn = $decoded->expires_in ?? null;
        if (!is_string($token) || '' === $token || !is_int($expiresIn)) {
            throw new PersonioApiException('Personio auth token endpoint returned an unexpected response.', 502);
        }

        $this->accessToken = $token;
        $this->tokenExpiresAt = time() + $expiresIn;

        return $token;
    }

    private function httpClient(): Client
    {
        return $this->client ??= $this->createHttpClient([
            'base_uri' => rtrim($this->baseUrl, '/') . '/',
        ]);
    }

    private function retryAfterSeconds(ResponseInterface $response): int
    {
        $header = $response->getHeaderLine('Retry-After');
        if (ctype_digit($header)) {
            return max(1, (int) $header);
        }

        return 1;
    }

    /**
     * @throws PersonioApiException
     */
    private function decodeObject(ResponseInterface $response): object
    {
        try {
            $decoded = json_decode((string) $response->getBody(), false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $jsonException) {
            throw new PersonioApiException('Personio API returned invalid JSON.', 502, $jsonException);
        }

        if (!is_object($decoded)) {
            throw new PersonioApiException('Personio API returned a non-object JSON response.', 502);
        }

        return $decoded;
    }

    /**
     * The list items under the `_data` envelope.
     *
     * @return list<object>
     */
    private function dataList(object $decoded): array
    {
        $data = $decoded->_data ?? null;
        if (!is_array($data)) {
            return [];
        }

        $items = [];
        foreach ($data as $item) {
            if (is_object($item)) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * Extracts the next cursor from `_meta.links.next.href`, if any.
     */
    private function nextCursor(object $decoded): ?string
    {
        $meta = $decoded->_meta ?? null;
        $links = is_object($meta) ? ($meta->links ?? null) : null;
        $next = is_object($links) ? ($links->next ?? null) : null;
        $href = is_object($next) ? ($next->href ?? null) : $next;
        if (!is_string($href) || '' === $href) {
            return null;
        }

        parse_str((string) parse_url($href, PHP_URL_QUERY), $params);
        $cursor = $params['cursor'] ?? null;

        return is_string($cursor) && '' !== $cursor ? $cursor : null;
    }

    /**
     * @throws PersonioApiException
     */
    private function extractId(object $decoded): string
    {
        $resource = $decoded->_data ?? $decoded;
        $id = is_object($resource) ? ($resource->id ?? null) : null;
        if (!is_string($id) && !is_int($id)) {
            throw new PersonioApiException('Personio create-attendance response contained no id.', 502);
        }

        return (string) $id;
    }

    /**
     * @return array{id: string, first_name: ?string, last_name: ?string, email: ?string}
     */
    private function mapPerson(object $item): array
    {
        /** @var array<string, mixed> $data */
        $data = (array) $item;

        return [
            'id' => is_string($data['id'] ?? null) || is_int($data['id'] ?? null) ? (string) $data['id'] : '',
            'first_name' => is_string($data['first_name'] ?? null) ? $data['first_name'] : null,
            'last_name' => is_string($data['last_name'] ?? null) ? $data['last_name'] : null,
            'email' => is_string($data['email'] ?? null) ? $data['email'] : null,
        ];
    }
}
