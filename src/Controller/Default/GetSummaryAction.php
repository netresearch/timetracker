<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Controller\Default;

use App\Controller\BaseController;
use App\Entity\Entry;
use App\Entity\User;
use App\Model\JsonResponse;
use App\Model\Response;
use App\Repository\EntryRepository;
use App\Response\Error;
use App\Service\Util\TimeCalculationService;
use Deprecated;
use Exception;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Service\Attribute\Required;

use function assert;
use function is_array;
use function sprintf;

final class GetSummaryAction extends BaseController
{
    private TimeCalculationService $timeCalculationService;

    #[Required]
    public function setTimeCalculationService(TimeCalculationService $timeCalculationService): void
    {
        $this->timeCalculationService = $timeCalculationService;
    }

    /**
     * @throws InvalidArgumentException When request parameters are invalid
     * @throws BadRequestException      When request parameters are malformed
     * @throws Exception                When database operations fail
     * @throws Exception                When time calculation operations fail
     */
    #[Route(path: '/getSummary', name: '_getSummary_attr', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    #[Deprecated(message: 'superseded by GET /api/v2/entries/{id}/summary (ADR-022); removal in v7')]
    public function __invoke(Request $request, #[CurrentUser] ?User $user = null): RedirectResponse|Response|JsonResponse|Error
    {
        if (!$user instanceof User) {
            return $this->redirectToRoute('_login');
        }

        $userId = (int) $user->getId();

        $data = [
            'customer' => ['scope' => 'customer', 'name' => '', 'entries' => 0, 'total' => 0, 'own' => 0, 'estimation' => 0],
            'project' => ['scope' => 'project', 'name' => '', 'entries' => 0, 'total' => 0, 'own' => 0, 'estimation' => 0],
            'activity' => ['scope' => 'activity', 'name' => '', 'entries' => 0, 'total' => 0, 'own' => 0, 'estimation' => 0],
            'ticket' => ['scope' => 'ticket', 'name' => '', 'entries' => 0, 'total' => 0, 'own' => 0, 'estimation' => 0],
        ];

        $entryId = $request->request->get('id');
        if (null === $entryId || '' === $entryId || false === $entryId) {
            return $this->deprecated(new JsonResponse($data), null);
        }

        $objectRepository = $this->managerRegistry->getRepository(Entry::class);
        assert($objectRepository instanceof EntryRepository);
        $entry = $objectRepository->find($entryId);
        // Owner-scoped like the v2 successor (ADR-022 §5 security exception to
        // the v1 freeze): the aggregation spans all users, so a foreign entry id
        // would disclose other users' scope names and totals (IDOR). "Not owned"
        // reads as "not found".
        if (!$entry instanceof Entry || $entry->getUser()?->getId() !== $userId) {
            $message = $this->translator->trans('No entry for id.');

            return $this->deprecated(new Error($message, \Symfony\Component\HttpFoundation\Response::HTTP_NOT_FOUND), (int) $entryId);
        }

        $data = $objectRepository->getEntrySummary((int) $entryId, $userId, $data);

        // Priority 1: Fix PossiblyUndefinedArrayOffset with proper array access validation
        if (isset($data['project']) && is_array($data['project']) && isset($data['project']['estimation']) && 0 !== $data['project']['estimation']) {
            // Safely access nested array values with type validation
            $projectTotal = isset($data['project']['total']) && is_numeric($data['project']['total'])
                ? (float) $data['project']['total']
                : null;

            $projectEstimation = is_numeric($data['project']['estimation'])
                ? (float) $data['project']['estimation']
                : null;

            // Only calculate quota if both values are available and valid
            if (null !== $projectTotal && null !== $projectEstimation) {
                $data['project']['quota'] = $this->timeCalculationService->formatQuota(
                    $projectTotal,
                    $projectEstimation,
                );
            }
        }

        return $this->deprecated(new JsonResponse($data), (int) $entryId);
    }

    /**
     * Marks the response as coming from a deprecated endpoint (ADR-022 §5) so
     * API consumers see it at call time.
     *
     * @template T of \Symfony\Component\HttpFoundation\Response
     *
     * @param T $response
     *
     * @return T
     */
    private function deprecated(\Symfony\Component\HttpFoundation\Response $response, ?int $entryId): \Symfony\Component\HttpFoundation\Response
    {
        $response->headers->set('Deprecation', 'true');
        // A concrete URI only — '{id}' URI templates are not valid in a Link
        // target, so without an entry id the header carries no Link.
        if (null !== $entryId) {
            $response->headers->set('Link', sprintf('</api/v2/entries/%d/summary>; rel="successor-version"', $entryId));
        }

        return $response;
    }
}
