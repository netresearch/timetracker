<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\BaseController;
use App\Dto\AccountSaveDto;
use App\Entity\Account;
use App\Model\JsonResponse;
use App\Model\Response;
use App\Response\Error;
use Exception;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class SaveAccountAction extends BaseController
{
    /**
     * @throws BadRequestException
     * @throws Exception
     */
    #[Route(path: '/account/save', name: 'saveAccount_attr', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function __invoke(#[MapRequestPayload] AccountSaveDto $accountSaveDto): Response|JsonResponse|Error
    {
        $objectRepository = $this->doctrineRegistry->getRepository(Account::class);

        if (0 !== $accountSaveDto->id) {
            $account = $objectRepository->find($accountSaveDto->id);
            if (!$account instanceof Account) {
                return new Error($this->translate('No entry for id.'), \Symfony\Component\HttpFoundation\Response::HTTP_NOT_FOUND);
            }
        } else {
            $account = new Account();
        }

        try {
            $account->setName($accountSaveDto->name);

            $em = $this->doctrineRegistry->getManager();
            $em->persist($account);
            $em->flush();
        } catch (Exception $exception) {
            $response = new Response($this->translate('Error on save') . ': ' . $exception->getMessage());
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_INTERNAL_SERVER_ERROR);

            return $response;
        }

        return new JsonResponse([$account->getId(), $account->getName()]);
    }
}
