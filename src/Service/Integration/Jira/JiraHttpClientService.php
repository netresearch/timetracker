<?php

declare(strict_types=1);

namespace App\Service\Integration\Jira;

use App\Entity\TicketSystem;
use App\Entity\User;
use App\Exception\Integration\Jira\JiraApiException;
use App\Exception\Integration\Jira\JiraApiInvalidResourceException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Subscriber\Oauth\Oauth1;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use stdClass;
use UnexpectedValueException;

use function is_array;
use function sprintf;

use const JSON_THROW_ON_ERROR;

/**
 * Manages HTTP clients for Jira API communication.
 * Handles OAuth configuration and request/response processing.
 */
class JiraHttpClientService
{
    private string $jiraApiUrl = '/rest/api/latest/';

    /** @var Client[] */
    private array $clients = [];

    public function __construct(
        private readonly User $user,
        private readonly TicketSystem $ticketSystem,
        private readonly JiraAuthenticationService $jiraAuthenticationService,
    ) {
    }

    /**
     * Gets configured HTTP client for different OAuth modes.
     *
     * @param string      $tokenMode  user|new|request
     * @param string|null $oAuthToken Request token when supplied
     *
     * @throws JiraApiException
     */
    public function getClient(string $tokenMode = 'user', ?string $oAuthToken = null): Client
    {
        $tokens = $this->resolveTokens($tokenMode, $oAuthToken);
        $key = $tokens['token'] . $tokens['secret'];

        if (isset($this->clients[$key])) {
            return $this->clients[$key];
        }

        $this->clients[$key] = $this->createClient($tokens['token'], $tokens['secret']);

        return $this->clients[$key];
    }

    /**
     * Resolves OAuth tokens based on mode.
     *
     * @return array{token: string, secret: string}
     */
    private function resolveTokens(string $tokenMode, ?string $oAuthToken): array
    {
        switch ($tokenMode) {
            case 'user':
                $tokens = $this->jiraAuthenticationService->getTokens($this->user, $this->ticketSystem);
                if ('' === $tokens['token'] && '' === $tokens['secret']) {
                    $this->jiraAuthenticationService->throwUnauthorizedRedirect($this->ticketSystem);
                }

                return $tokens;

            case 'new':
                return ['token' => '', 'secret' => ''];

            case 'request':
                return ['token' => $oAuthToken ?? '', 'secret' => ''];

            default:
                throw new UnexpectedValueException('Invalid token mode: ' . $tokenMode);
        }
    }

    /**
     * Creates configured Guzzle client with OAuth.
     */
    private function createClient(string $oAuthToken, string $oAuthTokenSecret): Client
    {
        $curlHandler = new CurlHandler();
        $handlerStack = HandlerStack::create($curlHandler);

        $oauth1 = new Oauth1([
            'consumer_key' => $this->getOAuthConsumerKey(),
            'consumer_secret' => $this->getOAuthConsumerSecret(),
            'private_key_file' => $this->getPrivateKeyFile(),
            'private_key_passphrase' => '',
            'signature_method' => Oauth1::SIGNATURE_METHOD_RSA,
            'token' => $oAuthToken,
            'token_secret' => $oAuthTokenSecret,
        ]);

        $handlerStack->push($oauth1);

        return new Client([
            'base_uri' => $this->getJiraBaseUrl(),
            'handler' => $handlerStack,
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
        ]);
    }

    /**
     * Performs GET request to Jira API.
     *
     * @throws JiraApiException
     */
    public function get(string $url): mixed
    {
        return $this->sendRequest('GET', $url);
    }

    /**
     * Performs POST request to Jira API.
     *
     * @param array<string, mixed> $data
     *
     * @throws JiraApiException
     */
    public function post(string $url, array $data = []): mixed
    {
        return $this->sendRequest('POST', $url, $data);
    }

    /**
     * Performs PUT request to Jira API.
     *
     * @param array<string, mixed> $data
     *
     * @throws JiraApiException
     */
    public function put(string $url, array $data = []): mixed
    {
        return $this->sendRequest('PUT', $url, $data);
    }

    /**
     * Performs DELETE request to Jira API.
     *
     * @throws JiraApiException
     */
    public function delete(string $url): mixed
    {
        return $this->sendRequest('DELETE', $url);
    }

    /**
     * Sends HTTP request to Jira API.
     *
     * @param array<string, mixed> $data
     *
     * @throws JiraApiException
     */
    private function sendRequest(string $method, string $url, array $data = []): mixed
    {
        try {
            $client = $this->getClient();
            $fullUrl = $this->jiraApiUrl . ltrim($url, '/');

            $options = ['auth' => 'oauth'];
            if ([] !== $data) {
                $options['json'] = $data;
            }

            $response = $client->request($method, $fullUrl, $options);
            $body = (string) $response->getBody();

            if ('' === $body) {
                return new stdClass();
            }

            return json_decode($body, false, 512, JSON_THROW_ON_ERROR);
        } catch (GuzzleException $e) {
            $this->handleGuzzleException($e, $url);
        } catch (JsonException $e) {
            throw new JiraApiException('Invalid JSON response from Jira: ' . $e->getMessage(), 500, null, $e);
        }
    }

    /**
     * Checks if resource exists in Jira.
     */
    public function doesResourceExist(string $url): bool
    {
        try {
            $client = $this->getClient();
            $fullUrl = $this->jiraApiUrl . ltrim($url, '/');

            $response = $client->request('HEAD', $fullUrl, ['auth' => 'oauth']);

            return 200 === $response->getStatusCode();
        } catch (GuzzleException) {
            return false;
        }
    }

    /**
     * Handles Guzzle exceptions and converts to Jira exceptions.
     *
     * @throws JiraApiException
     */
    private function handleGuzzleException(GuzzleException $guzzleException, string $url): never
    {
        // Check if this is a RequestException with a response
        $response = null;
        if ($guzzleException instanceof RequestException) {
            $response = $guzzleException->getResponse();
        }

        if (!$response instanceof ResponseInterface) {
            throw new JiraApiException('Network error connecting to Jira: ' . $guzzleException->getMessage(), 500, null, $guzzleException);
        }

        $statusCode = $response->getStatusCode();
        $body = (string) $response->getBody();

        $errorMessage = $this->extractErrorMessage($body);

        switch ($statusCode) {
            case 401:
                $this->jiraAuthenticationService->throwUnauthorizedRedirect($this->ticketSystem, $guzzleException);

                // no break
            case 404:
                throw new JiraApiInvalidResourceException(sprintf('Resource not found: %s', $url), 404, null, $guzzleException);
            default:
                throw new JiraApiException(sprintf('Jira API error [%d]: %s', $statusCode, $errorMessage), $statusCode, null, $guzzleException);
        }
    }

    /**
     * Extracts error message from Jira response.
     */
    private function extractErrorMessage(string $body): string
    {
        if ('' === $body) {
            return 'Empty response';
        }

        try {
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

            if (!is_array($data)) {
                return $body;
            }

            if (isset($data['errorMessages']) && is_array($data['errorMessages'])) {
                return implode(', ', $data['errorMessages']);
            }

            if (isset($data['errors']) && is_array($data['errors'])) {
                return implode(', ', array_values($data['errors']));
            }

            return $body;
        } catch (JsonException) {
            return $body;
        }
    }

    /**
     * Gets private key file path.
     */
    private function getPrivateKeyFile(): string
    {
        $certificate = $this->ticketSystem->getPrivateKey();

        if ('' === $certificate || '0' === $certificate) {
            throw new JiraApiException('OAuth private key not configured', 500);
        }

        return $this->getTempKeyFile($certificate);
    }

    /**
     * Creates temporary file with private key.
     */
    private function getTempKeyFile(string $certificate): string
    {
        $tempFile = $this->getTempFile();

        if (false === file_put_contents($tempFile, $certificate)) {
            throw new JiraApiException('Could not write private key to temp file', 500);
        }

        return $tempFile;
    }

    /**
     * Gets temporary file path.
     */
    private function getTempFile(): string
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'jira_oauth_');

        if (false === $tempFile) {
            throw new JiraApiException('Could not create temp file', 500);
        }

        // Register cleanup on shutdown
        register_shutdown_function(static function () use ($tempFile): void {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        });

        return $tempFile;
    }

    /**
     * Gets Jira base URL.
     */
    private function getJiraBaseUrl(): string
    {
        return rtrim($this->ticketSystem->getUrl(), '/');
    }

    /**
     * Gets OAuth consumer key.
     */
    private function getOAuthConsumerKey(): string
    {
        return $this->ticketSystem->getLogin() ?? '';
    }

    /**
     * Gets OAuth consumer secret.
     */
    private function getOAuthConsumerSecret(): string
    {
        return 'unused_secret';
    }

    /**
     * Gets ticket system for external access.
     */
    public function getTicketSystem(): TicketSystem
    {
        return $this->ticketSystem;
    }

    /**
     * Gets user for external access.
     */
    public function getUser(): User
    {
        return $this->user;
    }
}
