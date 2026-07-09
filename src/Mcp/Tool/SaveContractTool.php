<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Controller\Admin\SaveContractAction;
use App\Dto\ContractSaveDto;
use App\Mcp\AdminEntityResolver;
use App\Mcp\DecodesActionResponse;
use App\Mcp\ScopeGuard;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Validator\Validator\ValidatorInterface;

use function count;

/**
 * MCP admin tool: create or update an employment contract (ADR-022 Phase 4).
 * Both gates: ROLE_ADMIN and contracts:write.
 */
final readonly class SaveContractTool
{
    use DecodesActionResponse;

    public function __construct(
        private ScopeGuard $scopeGuard,
        private SaveContractAction $saveContractAction,
        private AdminEntityResolver $resolver,
        private ValidatorInterface $validator,
    ) {
    }

    /**
     * Create a contract (omit `contractId`) or update one (pass `contractId`)
     * for a user, with the daily target hours per weekday. `end` empty means an
     * open-ended contract. Requires an administrator account and the
     * contracts:write scope.
     *
     * @throws ToolCallException on a validation failure or unknown user
     *
     * @return array<array-key, mixed> the saved contract id
     */
    #[McpTool(name: 'save_contract', description: 'Create or update an employment contract (admin only).')]
    public function saveContract(
        #[Schema(description: 'User: username or numeric id.')]
        string $user,
        #[Schema(description: 'Start date YYYY-MM-DD.')]
        string $start,
        #[Schema(description: 'End date YYYY-MM-DD; empty for open-ended.')]
        string $end = '',
        #[Schema(description: 'Target hours on Monday.')]
        float $mondayHours = 8.0,
        #[Schema(description: 'Target hours on Tuesday.')]
        float $tuesdayHours = 8.0,
        #[Schema(description: 'Target hours on Wednesday.')]
        float $wednesdayHours = 8.0,
        #[Schema(description: 'Target hours on Thursday.')]
        float $thursdayHours = 8.0,
        #[Schema(description: 'Target hours on Friday.')]
        float $fridayHours = 8.0,
        #[Schema(description: 'Target hours on Saturday.')]
        float $saturdayHours = 0.0,
        #[Schema(description: 'Target hours on Sunday.')]
        float $sundayHours = 0.0,
        #[Schema(description: 'Existing contract id to update; omit to create.', minimum: 1)]
        ?int $contractId = null,
    ): array {
        $this->scopeGuard->requireAdminScope('contracts:write');

        // ContractSaveDto weekday convention: hours_0 = Sunday … hours_6 = Saturday.
        $dto = new ContractSaveDto(
            id: $contractId ?? 0,
            user_id: (int) $this->resolver->user($user)->getId(),
            start: $start,
            end: '' !== $end ? $end : null,
            hours_0: $sundayHours,
            hours_1: $mondayHours,
            hours_2: $tuesdayHours,
            hours_3: $wednesdayHours,
            hours_4: $thursdayHours,
            hours_5: $fridayHours,
            hours_6: $saturdayHours,
        );
        $this->assertValid($dto);

        $response = ($this->saveContractAction)($dto);
        $body = $this->decodeBody($response);
        if ($response->getStatusCode() >= Response::HTTP_BAD_REQUEST) {
            throw new ToolCallException($this->errorMessageFromResponse($response, 'Failed to save the contract.'));
        }

        return ['contract' => $body];
    }

    private function assertValid(object $dto): void
    {
        $violations = $this->validator->validate($dto);
        if (count($violations) > 0) {
            throw new ToolCallException((string) $violations->get(0)->getMessage());
        }
    }
}
