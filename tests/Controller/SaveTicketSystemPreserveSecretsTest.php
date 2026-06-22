<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Controller;

use App\Entity\TicketSystem;
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

        $container = $this->client->getContainer();
        /** @var \Doctrine\Bundle\DoctrineBundle\Registry $doctrine */
        $doctrine = $container->get('doctrine');
        $em = $doctrine->getManager();
        $em->clear();

        $ticketSystem = $em->getRepository(TicketSystem::class)->find(1);
        self::assertInstanceOf(TicketSystem::class, $ticketSystem);

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

        $container = $this->client->getContainer();
        /** @var \Doctrine\Bundle\DoctrineBundle\Registry $doctrine */
        $doctrine = $container->get('doctrine');
        $em = $doctrine->getManager();
        $em->clear();

        $ticketSystem = $em->getRepository(TicketSystem::class)->find(1);
        self::assertInstanceOf(TicketSystem::class, $ticketSystem);

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

        $container = $this->client->getContainer();
        /** @var \Doctrine\Bundle\DoctrineBundle\Registry $doctrine */
        $doctrine = $container->get('doctrine');
        $em = $doctrine->getManager();
        $em->clear();

        $ticketSystem = $em->getRepository(TicketSystem::class)->find(1);
        self::assertInstanceOf(TicketSystem::class, $ticketSystem);

        self::assertSame('CLOUD', $ticketSystem->getDeploymentTypeRaw());
        self::assertSame('mapped-client-id', $ticketSystem->getOauth2ClientId());
    }
}
