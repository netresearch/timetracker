<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\BaseController;
use App\Entity\Account;
use App\Model\JsonResponse;
use App\Model\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class GetAccountsAction extends BaseController
{
    #[Route(path: '/getAllAccounts', name: '_getAllAccounts_attr', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function __invoke(): Response|JsonResponse
    {
        $accounts = $this->doctrineRegistry->getRepository(Account::class)->findBy([], ['name' => 'ASC']);

        // Row-wrapped to match the other admin list endpoints ({customer:…}, {team:…}).
        $data = array_map(
            static fn (Account $account): array => ['account' => ['id' => $account->getId(), 'name' => $account->getName()]],
            $accounts,
        );

        return new JsonResponse($data);
    }
}
