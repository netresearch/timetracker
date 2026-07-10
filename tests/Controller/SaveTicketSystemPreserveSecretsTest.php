<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Controller;

use App\Entity\Activity;
use App\Entity\TicketSystem;
use App\Entity\User;
use Symfony\Component\HttpFoundation\Request;
use Tests\AbstractWebTestCase;

/**
 * /ticketsystem/save must not wipe stored credentials when the edit form
 * submits them blank.
 *
 * GetTicketSystemsAction strips password/public+private key/OAuth consumer
 * key+secret from the list payload, so the SolidJS edit form opens those
 * fields empty. A blank submission therefore has to mean "keep the stored
 * value", not "overwrite it with an empty string".
 *
 * @internal
 *
 * @coversNothing
 */
final class SaveTicketSystemPreserveSecretsTest extends AbstractWebTestCase
{
    private const array SECRETS = [
        'password' => 'stored-password',
        'publicKey' => 'stored-public-key',
        'privateKey' => 'stored-private-key',
        'oauthConsumerKey' => 'stored-consumer-key',
        'oauthConsumerSecret' => 'stored-consumer-secret',
        'oauth2ClientSecret' => 'stored-oauth2-secret',
    ];

    private function seedSecrets(): void
    {
        $container = $this->client->getContainer();
        /** @var \Doctrine\Bundle\DoctrineBundle\Registry $doctrine */
        $doctrine = $container->get('doctrine');
        $em = $doctrine->getManager();
        $ticketSystem = $em->getRepository(TicketSystem::class)->find(1);
        self::assertInstanceOf(TicketSystem::class, $ticketSystem);

        $ticketSystem->setPassword(self::SECRETS['password']);
        $ticketSystem->setPublicKey(self::SECRETS['publicKey']);
        $ticketSystem->setPrivateKey(self::SECRETS['privateKey']);
        $ticketSystem->setOauthConsumerKey(self::SECRETS['oauthConsumerKey']);
        $ticketSystem->setOauthConsumerSecret(self::SECRETS['oauthConsumerSecret']);
        $ticketSystem->setOauth2ClientSecret(self::SECRETS['oauth2ClientSecret']);

        $em->persist($ticketSystem);
        $em->flush();
    }

    /**
     * Re-fetch ticket system 1 from a cleared manager (so it reflects the saved
     * row, not the in-memory one mutated during the request).
     */
    private function refetchTicketSystem(): TicketSystem
    {
        $container = $this->client->getContainer();
        /** @var \Doctrine\Bundle\DoctrineBundle\Registry $doctrine */
        $doctrine = $container->get('doctrine');
        $em = $doctrine->getManager();
        $em->clear();

        $ticketSystem = $em->getRepository(TicketSystem::class)->find(1);
        self::assertInstanceOf(TicketSystem::class, $ticketSystem);

        return $ticketSystem;
    }

    public function testBlankCredentialsKeepStoredValuesAndOtherFieldsUpdate(): void
    {
        $this->logInSession('unittest');
        $this->seedSecrets();

        // Edit the system, changing only the name and leaving every credential
        // field blank (exactly what the secret-free edit form submits).
        $this->client->request(Request::METHOD_POST, '/ticketsystem/save', [
            'id' => 1,
            'name' => 'testSystemRenamed',
            'type' => 'JIRA',
            'url' => 'https://jira.example.test',
        ], [], ['HTTP_ACCEPT' => 'application/json']);
        $this->assertStatusCode(200);

        // The save response must not echo the stored credentials back to the
        // client (the list endpoint strips them too — same SECRET_KEYS).
        $body = (string) $this->client->getResponse()->getContent();
        foreach (self::SECRETS as $secret) {
            self::assertStringNotContainsString($secret, $body, 'save response leaked a credential');
        }

        $ticketSystem = $this->refetchTicketSystem();

        // Non-secret fields updated as submitted.
        self::assertSame('testSystemRenamed', $ticketSystem->getName());
        self::assertSame('https://jira.example.test', $ticketSystem->getUrl());

        // Credentials preserved, not wiped.
        self::assertSame(self::SECRETS['password'], $ticketSystem->getPassword());
        self::assertSame(self::SECRETS['publicKey'], $ticketSystem->getPublicKey());
        self::assertSame(self::SECRETS['privateKey'], $ticketSystem->getPrivateKey());
        self::assertSame(self::SECRETS['oauthConsumerKey'], $ticketSystem->getOauthConsumerKey());
        self::assertSame(self::SECRETS['oauthConsumerSecret'], $ticketSystem->getOauthConsumerSecret());
        self::assertSame(self::SECRETS['oauth2ClientSecret'], $ticketSystem->getOauth2ClientSecret());
    }

    public function testNonBlankCredentialsAreUpdated(): void
    {
        $this->logInSession('unittest');
        $this->seedSecrets();

        $this->client->request(Request::METHOD_POST, '/ticketsystem/save', [
            'id' => 1,
            'name' => 'testSystem',
            'type' => 'JIRA',
            'password' => 'rotated-password',
            'oauthConsumerSecret' => 'rotated-secret',
            'oauth2ClientSecret' => 'rotated-oauth2-secret',
        ], [], ['HTTP_ACCEPT' => 'application/json']);
        $this->assertStatusCode(200);

        $ticketSystem = $this->refetchTicketSystem();

        // Supplied credentials overwrite; untouched ones keep their stored value.
        self::assertSame('rotated-password', $ticketSystem->getPassword());
        self::assertSame('rotated-secret', $ticketSystem->getOauthConsumerSecret());
        self::assertSame('rotated-oauth2-secret', $ticketSystem->getOauth2ClientSecret());
        self::assertSame(self::SECRETS['privateKey'], $ticketSystem->getPrivateKey());
    }

    public function testDeploymentTypeAndOauth2ClientIdMapOntoEntity(): void
    {
        $this->logInSession('unittest');
        $this->seedSecrets();

        // The non-secret Cloud fields flow through the ObjectMapper onto the
        // entity. oauth2ClientId is non-secret, so it is sent (and updated)
        // normally; deploymentType discriminates the transport.
        $this->client->request(Request::METHOD_POST, '/ticketsystem/save', [
            'id' => 1,
            'name' => 'testSystem',
            'type' => 'JIRA',
            'deploymentType' => 'CLOUD',
            'oauth2ClientId' => 'mapped-client-id',
        ], [], ['HTTP_ACCEPT' => 'application/json']);
        $this->assertStatusCode(200);

        $ticketSystem = $this->refetchTicketSystem();

        self::assertSame('CLOUD', $ticketSystem->getDeploymentTypeRaw());
        self::assertSame('mapped-client-id', $ticketSystem->getOauth2ClientId());
    }

    public function testSavePersistsSyncConfiguration(): void
    {
        $this->logInSession('unittest');

        $this->client->request(Request::METHOD_POST, '/ticketsystem/save', [
            'id' => 1,
            'name' => 'testSystem',
            'type' => 'JIRA',
            'syncUserId' => 2,
            'syncDefaultActivityId' => 1,
        ], [], ['HTTP_ACCEPT' => 'application/json']);
        $this->assertStatusCode(200);

        // toSafeArray() emits the relations as ids (camelCase + snake_case).
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame(2, $body['sync_user']);
        self::assertSame(1, $body['sync_default_activity']);

        $ticketSystem = $this->refetchTicketSystem();

        $syncUser = $ticketSystem->getSyncUser();
        self::assertInstanceOf(User::class, $syncUser);
        self::assertSame(2, $syncUser->getId());

        $syncDefaultActivity = $ticketSystem->getSyncDefaultActivity();
        self::assertInstanceOf(Activity::class, $syncDefaultActivity);
        self::assertSame(1, $syncDefaultActivity->getId());
    }

    public function testSaveClearsSyncConfigurationWithNulls(): void
    {
        $this->logInSession('unittest');
        $this->seedSyncConfiguration();

        // The admin form always sends the full config — omitted/null sync
        // fields mean "clear the setting", not "keep it".
        $this->client->request(Request::METHOD_POST, '/ticketsystem/save', [
            'id' => 1,
            'name' => 'testSystem',
            'type' => 'JIRA',
        ], [], ['HTTP_ACCEPT' => 'application/json']);
        $this->assertStatusCode(200);

        $ticketSystem = $this->refetchTicketSystem();

        self::assertNull($ticketSystem->getSyncUser());
        self::assertNull($ticketSystem->getSyncDefaultActivity());
    }

    public function testSaveRejectsUnknownSyncUser(): void
    {
        $this->logInSession('unittest');

        $this->client->request(Request::METHOD_POST, '/ticketsystem/save', [
            'id' => 1,
            'name' => 'testSystem',
            'type' => 'JIRA',
            'syncUserId' => 999,
        ], [], ['HTTP_ACCEPT' => 'application/json']);
        $this->assertStatusCode(422);
    }

    private function seedSyncConfiguration(): void
    {
        $container = $this->client->getContainer();
        /** @var \Doctrine\Bundle\DoctrineBundle\Registry $doctrine */
        $doctrine = $container->get('doctrine');
        $em = $doctrine->getManager();

        $ticketSystem = $em->getRepository(TicketSystem::class)->find(1);
        self::assertInstanceOf(TicketSystem::class, $ticketSystem);
        $syncUser = $em->getRepository(User::class)->find(2);
        self::assertInstanceOf(User::class, $syncUser);
        $syncDefaultActivity = $em->getRepository(Activity::class)->find(1);
        self::assertInstanceOf(Activity::class, $syncDefaultActivity);

        $ticketSystem->setSyncUser($syncUser);
        $ticketSystem->setSyncDefaultActivity($syncDefaultActivity);

        $em->persist($ticketSystem);
        $em->flush();
    }
}
