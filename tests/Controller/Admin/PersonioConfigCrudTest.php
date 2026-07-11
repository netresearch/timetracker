<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Controller\Admin;

use App\Entity\PersonioConfig;
use App\Service\Security\TokenEncryptionService;
use Symfony\Component\HttpFoundation\Request;
use Tests\AbstractWebTestCase;

/**
 * Admin CRUD for the Personio configuration (ADR-024 §2).
 *
 * The client secret is stored encrypted at rest (unlike the plaintext Jira
 * precedent), stripped from list/save responses, and preserved when the
 * secret-free edit form submits it blank.
 *
 * @internal
 *
 * @coversNothing
 */
final class PersonioConfigCrudTest extends AbstractWebTestCase
{
    /**
     * Re-fetch a config from a cleared manager so it reflects the persisted row.
     */
    private function refetchByName(string $name): PersonioConfig
    {
        $container = $this->client->getContainer();
        /** @var \Doctrine\Bundle\DoctrineBundle\Registry $doctrine */
        $doctrine = $container->get('doctrine');
        $em = $doctrine->getManager();
        $em->clear();

        $config = $em->getRepository(PersonioConfig::class)->findOneBy(['name' => $name]);
        self::assertInstanceOf(PersonioConfig::class, $config);

        return $config;
    }

    public function testSavePersistsAndEncryptsSecret(): void
    {
        $this->logInSession('unittest');

        $this->client->request(Request::METHOD_POST, '/personio-config/save', [
            'name' => 'Personio',
            'baseUrl' => 'https://api.personio.de',
            'clientId' => 'client-abc',
            'clientSecret' => 'super-secret-value',
            'active' => true,
        ], [], ['HTTP_ACCEPT' => 'application/json']);
        $this->assertStatusCode(200);

        // The save response must not echo the client secret back.
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('super-secret-value', $body);

        $config = $this->refetchByName('Personio');
        self::assertSame('https://api.personio.de', $config->getBaseUrl());
        self::assertSame('client-abc', $config->getClientId());

        // Stored column holds ciphertext, not the plaintext.
        $stored = $config->getClientSecret();
        self::assertNotSame('super-secret-value', $stored);
        self::assertNotSame('', $stored);

        // …that decrypts back to the plaintext.
        $encryptionService = self::getContainer()->get(TokenEncryptionService::class);
        self::assertInstanceOf(TokenEncryptionService::class, $encryptionService);
        self::assertSame('super-secret-value', $encryptionService->decryptToken($stored));
    }

    public function testCreateWithZeroIdCreatesNew(): void
    {
        $this->logInSession('unittest');

        // The admin CRUD frontend posts id: 0 for a new config (toForm(null)
        // emits id: 0). A zero id must create, not be looked up as an existing
        // row and 404 — this is the exact payload the UI sends.
        $this->client->request(Request::METHOD_POST, '/personio-config/save', [
            'id' => 0,
            'name' => 'Personio',
            'baseUrl' => 'https://api.personio.de',
            'clientId' => 'client-abc',
            'clientSecret' => 'super-secret-value',
            'active' => true,
        ], [], ['HTTP_ACCEPT' => 'application/json']);
        $this->assertStatusCode(200);

        $config = $this->refetchByName('Personio');
        self::assertNotNull($config->getId());
        self::assertSame('client-abc', $config->getClientId());
    }

    public function testSaveBlankSecretKeepsStored(): void
    {
        $this->logInSession('unittest');

        $this->client->request(Request::METHOD_POST, '/personio-config/save', [
            'name' => 'Personio',
            'baseUrl' => 'https://api.personio.de',
            'clientId' => 'client-abc',
            'clientSecret' => 'first-secret',
        ], [], ['HTTP_ACCEPT' => 'application/json']);
        $this->assertStatusCode(200);

        $created = $this->refetchByName('Personio');
        $storedCiphertext = $created->getClientSecret();
        $id = $created->getId();

        // Second save with a blank secret must keep the stored ciphertext, and
        // still update the non-secret fields.
        $this->client->request(Request::METHOD_POST, '/personio-config/save', [
            'id' => $id,
            'name' => 'Personio',
            'baseUrl' => 'https://api.personio.example',
            'clientId' => 'client-xyz',
            'clientSecret' => '',
        ], [], ['HTTP_ACCEPT' => 'application/json']);
        $this->assertStatusCode(200);

        $config = $this->refetchByName('Personio');
        self::assertSame('https://api.personio.example', $config->getBaseUrl());
        self::assertSame('client-xyz', $config->getClientId());
        self::assertSame($storedCiphertext, $config->getClientSecret());

        $encryptionService = self::getContainer()->get(TokenEncryptionService::class);
        self::assertInstanceOf(TokenEncryptionService::class, $encryptionService);
        self::assertSame('first-secret', $encryptionService->decryptToken($config->getClientSecret()));
    }

    public function testDuplicateNameRejected(): void
    {
        $this->logInSession('unittest');

        $payload = [
            'name' => 'Personio',
            'baseUrl' => 'https://api.personio.de',
            'clientId' => 'client-abc',
            'clientSecret' => 'secret',
        ];
        $this->client->request(Request::METHOD_POST, '/personio-config/save', $payload, [], ['HTTP_ACCEPT' => 'application/json']);
        $this->assertStatusCode(200);

        // A second, different config reusing the name is rejected with 406.
        $this->client->request(Request::METHOD_POST, '/personio-config/save', $payload, [], ['HTTP_ACCEPT' => 'application/json']);
        $this->assertStatusCode(406);
    }

    public function testListStripsSecret(): void
    {
        $this->logInSession('unittest');

        $this->client->request(Request::METHOD_POST, '/personio-config/save', [
            'name' => 'Personio',
            'baseUrl' => 'https://api.personio.de',
            'clientId' => 'client-abc',
            'clientSecret' => 'super-secret-value',
        ], [], ['HTTP_ACCEPT' => 'application/json']);
        $this->assertStatusCode(200);

        $this->client->request(Request::METHOD_GET, '/getPersonioConfigs', [], [], ['HTTP_ACCEPT' => 'application/json']);
        $this->assertStatusCode(200);

        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('super-secret-value', $body);
        self::assertStringNotContainsString('client_secret', $body);
        self::assertStringNotContainsString('clientSecret', $body);

        /** @var array<int, array{personio: array<string, mixed>}> $rows */
        $rows = json_decode($body, true);
        self::assertCount(1, $rows);
        self::assertSame('Personio', $rows[0]['personio']['name']);
    }

    public function testDeleteRemoves(): void
    {
        $this->logInSession('unittest');

        $this->client->request(Request::METHOD_POST, '/personio-config/save', [
            'name' => 'Personio',
            'baseUrl' => 'https://api.personio.de',
            'clientId' => 'client-abc',
            'clientSecret' => 'secret',
        ], [], ['HTTP_ACCEPT' => 'application/json']);
        $this->assertStatusCode(200);

        $id = $this->refetchByName('Personio')->getId();

        $this->client->request(Request::METHOD_POST, '/personio-config/delete', [
            'id' => $id,
        ], [], ['HTTP_ACCEPT' => 'application/json']);
        $this->assertStatusCode(200);

        $container = $this->client->getContainer();
        /** @var \Doctrine\Bundle\DoctrineBundle\Registry $doctrine */
        $doctrine = $container->get('doctrine');
        $em = $doctrine->getManager();
        $em->clear();
        self::assertNull($em->getRepository(PersonioConfig::class)->find($id));
    }
}
