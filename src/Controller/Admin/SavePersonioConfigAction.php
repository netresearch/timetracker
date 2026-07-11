<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\BaseController;
use App\Dto\PersonioConfigSaveDto;
use App\Entity\PersonioConfig;
use App\Entity\Project;
use App\Model\JsonResponse;
use App\Model\Response;
use App\Repository\PersonioConfigRepository;
use App\Response\Error;
use App\Service\Security\TokenEncryptionService;
use Exception;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\ObjectMapper\ObjectMapperInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use function assert;

final class SavePersonioConfigAction extends BaseController
{
    public function __construct(
        private readonly ObjectMapperInterface $objectMapper,
        private readonly TokenEncryptionService $tokenEncryptionService,
    ) {
    }

    /**
     * @throws BadRequestException              When request payload is malformed
     * @throws UnprocessableEntityHttpException When DTO validation fails
     * @throws Exception                        When object mapping or persistence operations fail
     */
    #[Route(path: '/personio-config/save', name: 'savePersonioConfig_attr', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function __invoke(#[MapRequestPayload] PersonioConfigSaveDto $personioConfigSaveDto): Response|Error|JsonResponse
    {
        $objectRepository = $this->doctrineRegistry->getRepository(PersonioConfig::class);
        assert($objectRepository instanceof PersonioConfigRepository);

        $id = $personioConfigSaveDto->id;

        if (0 !== $id) {
            $personioConfig = $objectRepository->find($id);
            if (!$personioConfig instanceof PersonioConfig) {
                return new Error($this->translate('No entry for id.'), \Symfony\Component\HttpFoundation\Response::HTTP_NOT_FOUND);
            }
        } else {
            $personioConfig = new PersonioConfig();
        }

        $sameNamedConfig = $objectRepository->findOneByName($personioConfigSaveDto->name);
        if ($sameNamedConfig instanceof PersonioConfig && $personioConfig->getId() !== $sameNamedConfig->getId()) {
            $response = new Response($this->translate('The Personio configuration name provided already exists.'));
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);

            return $response;
        }

        // The list payload (GetPersonioConfigsAction) never ships the client
        // secret, so the edit form opens that field blank. For an EXISTING
        // config a blank submission must mean "keep the stored (encrypted)
        // value", not "wipe it": snapshot the stored ciphertext up front.
        $storedSecret = 0 !== $id ? $personioConfig->getClientSecret() : null;

        // On CREATE there is no stored ciphertext to fall back on, so a blank
        // secret would persist a config that can never authenticate — reject it.
        if (0 === $id && '' === $personioConfigSaveDto->clientSecret) {
            return new Error($this->translate('Client secret is required.'), \Symfony\Component\HttpFoundation\Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $absenceProjectError = $this->applyAbsenceProject($personioConfig, $personioConfigSaveDto);
        if ($absenceProjectError instanceof Error) {
            return $absenceProjectError;
        }

        try {
            $this->objectMapper->map($personioConfigSaveDto, $personioConfig);

            // The secret is stored encrypted at rest (ADR-024 §2 — stricter than
            // the plaintext Jira precedent). A blank submission keeps the stored
            // ciphertext; a non-blank one is encrypted before persist.
            if ('' === $personioConfigSaveDto->clientSecret) {
                if (null !== $storedSecret) {
                    $personioConfig->setClientSecret($storedSecret);
                }
            } else {
                $personioConfig->setClientSecret($this->tokenEncryptionService->encryptToken($personioConfigSaveDto->clientSecret));
            }

            $em = $this->doctrineRegistry->getManager();
            $em->persist($personioConfig);
            $em->flush();
        } catch (Exception $exception) {
            $response = new Response($this->translate('Error on save') . ': ' . $exception->getMessage());
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_INTERNAL_SERVER_ERROR);

            return $response;
        }

        // Never echo the client secret back to the browser (the list endpoint
        // withholds it too — same SECRET_KEYS).
        return new JsonResponse($personioConfig->toSafeArray());
    }

    /**
     * Resolve the absence project (used by the P2 absence import). The admin
     * form always sends the full config, so a null id clears the relation; an
     * unknown id is rejected with 422.
     */
    private function applyAbsenceProject(PersonioConfig $personioConfig, PersonioConfigSaveDto $dto): ?Error
    {
        $absenceProject = null;
        if (null !== $dto->absenceProjectId) {
            $absenceProject = $this->doctrineRegistry->getRepository(Project::class)->find($dto->absenceProjectId);
            if (!$absenceProject instanceof Project) {
                return new Error($this->translate('Unknown absence project.'), \Symfony\Component\HttpFoundation\Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        $personioConfig->setAbsenceProject($absenceProject);

        return null;
    }
}
