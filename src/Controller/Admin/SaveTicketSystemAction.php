<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\BaseController;
use App\Dto\TicketSystemSaveDto;
use App\Entity\Activity;
use App\Entity\TicketSystem;
use App\Model\JsonResponse;
use App\Model\Response;
use App\Repository\TicketSystemRepository;
use App\Response\Error;
use Exception;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use function assert;

final class SaveTicketSystemAction extends BaseController
{
    public function __construct(private readonly ObjectMapperInterface $objectMapper)
    {
    }

    /**
     * @throws BadRequestException              When request payload is malformed
     * @throws UnprocessableEntityHttpException When DTO validation fails
     * @throws Exception                        When database operations fail
     * @throws Exception                        When object mapping or persistence operations fail
     */
    #[Route(path: '/ticketsystem/save', name: 'saveTicketSystem_attr', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function __invoke(#[MapRequestPayload] TicketSystemSaveDto $ticketSystemSaveDto): Response|Error|JsonResponse
    {
        $objectRepository = $this->doctrineRegistry->getRepository(TicketSystem::class);
        assert($objectRepository instanceof TicketSystemRepository);

        $id = $ticketSystemSaveDto->id;

        if (null !== $id) {
            $ticketSystem = $objectRepository->find($id);
            if (null === $ticketSystem) {
                $message = $this->translator->trans('No entry for id.');

                return new Error($message, \Symfony\Component\HttpFoundation\Response::HTTP_NOT_FOUND);
            }

            if (!$ticketSystem instanceof TicketSystem) {
                return new Error($this->translate('No entry for id.'), \Symfony\Component\HttpFoundation\Response::HTTP_NOT_FOUND);
            }
        } else {
            $ticketSystem = new TicketSystem();
        }

        // Basic length validation handled by DTO constraints via MapRequestPayload (422)

        $sameNamedSystem = $objectRepository->findOneByName($ticketSystemSaveDto->name);
        if ($sameNamedSystem instanceof TicketSystem && $ticketSystem->getId() !== $sameNamedSystem->getId()) {
            $response = new Response($this->translate('The ticket system name provided already exists.'));
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);

            return $response;
        }

        // The list payload (GetTicketSystemsAction) never ships credentials, so
        // the edit form opens these fields blank. For an EXISTING system a blank
        // submission must mean "keep the stored value", not "wipe it": capture
        // the stored secrets up front and restore any field that came in empty
        // after the DTO is mapped over. A brand-new system has nothing to
        // preserve (and its credential getters are not yet initialised), so this
        // only applies when editing.
        $storedSecrets = null !== $id ? $this->captureStoredSecrets($ticketSystem) : null;

        $syncConfigurationError = $this->applySyncConfiguration($ticketSystem, $ticketSystemSaveDto);
        if ($syncConfigurationError instanceof Error) {
            return $syncConfigurationError;
        }

        try {
            $this->objectMapper->map($ticketSystemSaveDto, $ticketSystem);

            if (null !== $storedSecrets) {
                $this->restoreBlankSecrets($ticketSystem, $ticketSystemSaveDto, $storedSecrets);
            }

            $em = $this->doctrineRegistry->getManager();
            $em->persist($ticketSystem);
            $em->flush();
        } catch (Exception $exception) {
            $response = new Response($this->translate('Error on save') . ': ' . $exception->getMessage());
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_INTERNAL_SERVER_ERROR);

            return $response;
        }

        // Strip the secret credentials the list endpoint also withholds — the
        // save response must not echo password/keys/OAuth secrets back to the
        // browser (they were just persisted server-side).
        return new JsonResponse($ticketSystem->toSafeArray());
    }

    /**
     * Resolve the worklog sync default activity (ADR-023 §5, opt-in amendment).
     * The admin form always sends the full config, so a null id clears the
     * setting; an unknown id is rejected with 422.
     */
    private function applySyncConfiguration(TicketSystem $ticketSystem, TicketSystemSaveDto $dto): ?Error
    {
        $syncDefaultActivity = null;
        if (null !== $dto->syncDefaultActivityId) {
            $syncDefaultActivity = $this->doctrineRegistry->getRepository(Activity::class)->find($dto->syncDefaultActivityId);
            if (!$syncDefaultActivity instanceof Activity) {
                return new Error($this->translate('Unknown sync default activity.'), \Symfony\Component\HttpFoundation\Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        $ticketSystem->setSyncDefaultActivity($syncDefaultActivity);

        return null;
    }

    /**
     * @return array{password: string, publicKey: string, privateKey: string, oauthConsumerKey: ?string, oauthConsumerSecret: ?string, oauth2ClientSecret: ?string}
     */
    private function captureStoredSecrets(TicketSystem $ticketSystem): array
    {
        return [
            'password' => $ticketSystem->getPassword(),
            'publicKey' => $ticketSystem->getPublicKey(),
            'privateKey' => $ticketSystem->getPrivateKey(),
            'oauthConsumerKey' => $ticketSystem->getOauthConsumerKey(),
            'oauthConsumerSecret' => $ticketSystem->getOauthConsumerSecret(),
            'oauth2ClientSecret' => $ticketSystem->getOauth2ClientSecret(),
        ];
    }

    /**
     * Restore any credential the secret-free edit form submitted blank to its
     * stored value, so a blank field means "keep" rather than "wipe".
     *
     * @param array{password: string, publicKey: string, privateKey: string, oauthConsumerKey: ?string, oauthConsumerSecret: ?string, oauth2ClientSecret: ?string} $storedSecrets
     */
    private function restoreBlankSecrets(TicketSystem $ticketSystem, TicketSystemSaveDto $dto, array $storedSecrets): void
    {
        if ('' === $dto->password) {
            $ticketSystem->setPassword($storedSecrets['password']);
        }

        if ('' === $dto->publicKey) {
            $ticketSystem->setPublicKey($storedSecrets['publicKey']);
        }

        if ('' === $dto->privateKey) {
            $ticketSystem->setPrivateKey($storedSecrets['privateKey']);
        }

        if (null === $dto->oauthConsumerKey || '' === $dto->oauthConsumerKey) {
            $ticketSystem->setOauthConsumerKey($storedSecrets['oauthConsumerKey']);
        }

        if (null === $dto->oauthConsumerSecret || '' === $dto->oauthConsumerSecret) {
            $ticketSystem->setOauthConsumerSecret($storedSecrets['oauthConsumerSecret']);
        }

        if (null === $dto->oauth2ClientSecret || '' === $dto->oauth2ClientSecret) {
            $ticketSystem->setOauth2ClientSecret($storedSecrets['oauth2ClientSecret']);
        }
    }
}
