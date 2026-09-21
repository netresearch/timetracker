<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Service\Response;

use App\Exception\Integration\Jira\JiraApiException;
use App\Exception\Integration\Jira\JiraApiUnauthorizedException;
use App\Service\Response\ResponseFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Contracts\Translation\TranslatorInterface;

use function json_decode;

/**
 * @internal
 */
#[CoversClass(ResponseFactory::class)]
final class ResponseFactoryTest extends TestCase
{
    private ResponseFactory $responseFactory;

    protected function setUp(): void
    {
        // A translator that marks what it touched, so a response proves the
        // message went through translation rather than around it.
        $translator = new class implements TranslatorInterface {
            /**
             * @param array<array-key, mixed> $parameters
             */
            public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
            {
                return '[' . $id . ']';
            }

            public function getLocale(): string
            {
                return 'en';
            }
        };

        $this->responseFactory = new ResponseFactory($translator);
    }

    /**
     * @return array<string, mixed>
     */
    private static function payload(HttpResponse $httpResponse): array
    {
        $content = $httpResponse->getContent();
        self::assertIsString($content);
        $decoded = json_decode($content, true);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    public function testSuccessWithoutDataCarriesOnlyTheFlag(): void
    {
        self::assertSame(['success' => true], self::payload($this->responseFactory->success()));
    }

    public function testSuccessMergesDataAndAlert(): void
    {
        $jsonResponse = $this->responseFactory->success(['id' => 7], 'saved');

        self::assertSame(
            ['success' => true, 'id' => 7, 'alert' => 'saved'],
            self::payload($jsonResponse),
        );
    }

    public function testErrorTranslatesTheMessageAndKeepsStatusAndRedirect(): void
    {
        $error = $this->responseFactory->error('Broken', HttpResponse::HTTP_I_AM_A_TEAPOT, '/login');

        self::assertSame(HttpResponse::HTTP_I_AM_A_TEAPOT, $error->getStatusCode());
        self::assertSame(
            ['message' => '[Broken]', 'forwardUrl' => '/login'],
            self::payload($error),
        );
    }

    public function testNotFound(): void
    {
        $error = $this->responseFactory->notFound();

        self::assertSame(HttpResponse::HTTP_NOT_FOUND, $error->getStatusCode());
        self::assertSame('[Resource not found]', self::payload($error)['message']);
    }

    public function testUnauthorizedKeepsTheRedirect(): void
    {
        $error = $this->responseFactory->unauthorized('Nope', '/oauth');

        self::assertSame(HttpResponse::HTTP_UNAUTHORIZED, $error->getStatusCode());
        self::assertSame('/oauth', self::payload($error)['forwardUrl']);
    }

    public function testForbidden(): void
    {
        $error = $this->responseFactory->forbidden();

        self::assertSame(HttpResponse::HTTP_FORBIDDEN, $error->getStatusCode());
        self::assertSame('[Forbidden]', self::payload($error)['message']);
    }

    public function testValidationErrorListsEveryField(): void
    {
        $error = $this->responseFactory->validationError(['start' => 'is missing', 'end' => 'is before start']);

        self::assertSame(HttpResponse::HTTP_UNPROCESSABLE_ENTITY, $error->getStatusCode());
        self::assertSame(
            '[[Validation failed]: start: is missing, end: is before start]',
            self::payload($error)['message'],
        );
    }

    public function testValidationErrorWithoutFieldsKeepsTheBareMessage(): void
    {
        $error = $this->responseFactory->validationError([]);

        self::assertSame('[[Validation failed]]', self::payload($error)['message']);
    }

    public function testConflict(): void
    {
        $error = $this->responseFactory->conflict();

        self::assertSame(HttpResponse::HTTP_CONFLICT, $error->getStatusCode());
        self::assertSame('[Conflict detected]', self::payload($error)['message']);
    }

    public function testServerError(): void
    {
        $error = $this->responseFactory->serverError();

        self::assertSame(HttpResponse::HTTP_INTERNAL_SERVER_ERROR, $error->getStatusCode());
        self::assertSame('[Internal server error]', self::payload($error)['message']);
    }

    public function testFailedLoginIsUnauthorized(): void
    {
        $error = $this->responseFactory->failedLogin();

        self::assertSame(HttpResponse::HTTP_UNAUTHORIZED, $error->getStatusCode());
        self::assertSame('[Login failed. Please check your credentials.]', self::payload($error)['message']);
    }

    public function testPaginatedComputesBothNeighbourFlags(): void
    {
        $payload = self::payload($this->responseFactory->paginated(['a', 'b'], 2, 3, 25, 10));

        self::assertSame(['a', 'b'], $payload['items']);
        self::assertSame(
            [
                'page' => 2,
                'totalPages' => 3,
                'totalItems' => 25,
                'itemsPerPage' => 10,
                'hasNext' => true,
                'hasPrevious' => true,
            ],
            $payload['pagination'],
        );
    }

    public function testPaginatedOnTheOnlyPageHasNoNeighbours(): void
    {
        $pagination = self::payload($this->responseFactory->paginated([], 1, 1, 0, 10))['pagination'];
        self::assertIsArray($pagination);

        self::assertFalse($pagination['hasNext']);
        self::assertFalse($pagination['hasPrevious']);
    }

    public function testWithMetadataSeparatesDataFromMetadata(): void
    {
        $payload = self::payload($this->responseFactory->withMetadata(['id' => 1], ['total' => 1]));

        self::assertSame(['id' => 1], $payload['data']);
        self::assertSame(['total' => 1], $payload['metadata']);
    }

    public function testJiraApiErrorUnauthorizedBecomesForbiddenWithTheRedirect(): void
    {
        $error = $this->responseFactory->jiraApiError(
            new JiraApiUnauthorizedException('token expired', 401, '/jira/oauth'),
        );

        self::assertSame(HttpResponse::HTTP_FORBIDDEN, $error->getStatusCode());
        self::assertSame('[Jira: token expired]', self::payload($error)['message']);
        self::assertSame('/jira/oauth', self::payload($error)['forwardUrl']);
    }

    public function testJiraApiErrorBecomesBadGatewayAndSaysTheDatasetChangedAnyway(): void
    {
        $error = $this->responseFactory->jiraApiError(new JiraApiException('rate limited', 429));

        self::assertSame(HttpResponse::HTTP_BAD_GATEWAY, $error->getStatusCode());
        self::assertSame(
            '[Jira: rate limited<br />[Dataset was modified in Timetracker anyway]]',
            self::payload($error)['message'],
        );
    }

    public function testJiraApiErrorFallsBackToTheServerErrorForAnyOtherException(): void
    {
        $error = $this->responseFactory->jiraApiError(new RuntimeException('socket closed'));

        self::assertSame(HttpResponse::HTTP_INTERNAL_SERVER_ERROR, $error->getStatusCode());
        self::assertSame('[Jira API error occurred]', self::payload($error)['message']);
    }
}
