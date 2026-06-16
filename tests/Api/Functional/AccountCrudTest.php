<?php

declare(strict_types=1);

namespace Tests\Api\Functional;

use Symfony\Component\HttpFoundation\Request;
use Tests\AbstractWebTestCase;

use function is_array;

use const JSON_THROW_ON_ERROR;

/**
 * API Functional Tests - Account CRUD Operations (real database).
 *
 * @internal
 *
 * @coversNothing
 */
final class AccountCrudTest extends AbstractWebTestCase
{
    public function testCreateListUpdateDeleteAccount(): void
    {
        $this->logInSession('unittest');

        // Create
        $name = 'Acct ' . uniqid();
        $this->client->request(Request::METHOD_POST, '/account/save', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['name' => $name], JSON_THROW_ON_ERROR));
        $this->assertStatusCode(200);
        $created = $this->getJsonResponse($this->client->getResponse());
        self::assertIsArray($created);
        self::assertNotNull($created[0]); // id
        self::assertSame($name, $created[1]);
        $id = $created[0];

        // List — row-wrapped {account: {id, name}}
        $this->client->request(Request::METHOD_GET, '/getAllAccounts');
        $this->assertStatusCode(200);
        $list = $this->getJsonResponse($this->client->getResponse());
        self::assertIsArray($list);
        $found = false;
        foreach ($list as $item) {
            /** @var array<string, mixed> $item */
            $account = isset($item['account']) && is_array($item['account']) ? $item['account'] : $item;
            if (($account['id'] ?? null) === $id) {
                self::assertSame($name, $account['name']);
                $found = true;
            }
        }

        self::assertTrue($found, 'the created account appears in the list');

        // Update
        $renamed = $name . ' upd';
        $this->client->request(Request::METHOD_POST, '/account/save', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['id' => $id, 'name' => $renamed], JSON_THROW_ON_ERROR));
        $this->assertStatusCode(200);
        $updated = $this->getJsonResponse($this->client->getResponse());
        self::assertSame($renamed, $updated[1]);

        // Delete
        $this->client->request(Request::METHOD_POST, '/account/delete', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['id' => $id], JSON_THROW_ON_ERROR));
        $this->assertStatusCode(200);
        $deleted = $this->getJsonResponse($this->client->getResponse());
        self::assertTrue($deleted['success']);
    }

    public function testCreateAccountRejectsShortName(): void
    {
        $this->logInSession('unittest');

        // name shorter than the 3-char minimum → validation error
        $this->client->request(Request::METHOD_POST, '/account/save', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['name' => 'ab'], JSON_THROW_ON_ERROR));

        $statusCode = $this->client->getResponse()->getStatusCode();
        self::assertGreaterThanOrEqual(400, $statusCode);
        self::assertLessThan(500, $statusCode);
    }

    public function testSaveAccountWithUnknownIdReturnsNotFound(): void
    {
        $this->logInSession('unittest');

        // Updating a non-existent id falls through to the "No entry for id." branch.
        $this->client->request(Request::METHOD_POST, '/account/save', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['id' => 999999, 'name' => 'Ghost account'], JSON_THROW_ON_ERROR));

        $this->assertStatusCode(404);
    }

    public function testDeleteUnknownAccountReturnsError(): void
    {
        $this->logInSession('unittest');

        // Deleting a non-existent id raises EntityAlreadyDeletedException, which the
        // action maps to an unprocessable-entity error response.
        $this->client->request(Request::METHOD_POST, '/account/delete', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['id' => 999999], JSON_THROW_ON_ERROR));

        $this->assertStatusCode(422);
    }
}
