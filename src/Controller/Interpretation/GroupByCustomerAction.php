<?php

declare(strict_types=1);

namespace App\Controller\Interpretation;

use App\Entity\User;
use App\Model\JsonResponse;
use App\Model\Response as ModelResponse;
use App\Service\Util\TimeCalculationService;
use Exception;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class GroupByCustomerAction extends BaseInterpretationController
{
    private TimeCalculationService $timeCalculationService;

    #[\Symfony\Contracts\Service\Attribute\Required]
    public function setTimeCalculationService(TimeCalculationService $timeCalculationService): void
    {
        $this->timeCalculationService = $timeCalculationService;
    }

    #[\Symfony\Component\Routing\Attribute\Route(path: '/interpretation/customer', name: 'interpretation_customer_attr', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function __invoke(
        Request $request,
        #[CurrentUser] User $currentUser,
    ): ModelResponse|JsonResponse {

        try {
            $entries = $this->getEntries($request, $currentUser);
        } catch (Exception $exception) {
            $response = new ModelResponse($this->translate($exception->getMessage()));
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);

            return $response;
        }

        $customers = [];
        foreach ($entries as $entry) {
            $customerEntity = $entry->getCustomer();
            if (!$customerEntity) {
                continue;
            }

            $cid = $customerEntity->getId();
            if (!isset($customers[$cid])) {
                $customers[$cid] = ['id' => $cid, 'name' => $customerEntity->getName(), 'hours' => 0, 'quota' => 0];
            }

            $customers[$cid]['hours'] += $entry->getDuration() / 60;
        }

        $sum = 0;
        foreach ($customers as $c) {
            $sum += $c['hours'];
        }

        foreach ($customers as &$customer) {
            $customer['quota'] = $this->timeCalculationService->formatQuota($customer['hours'], $sum);
        }

        usort($customers, $this->sortByName(...));

        return new JsonResponse($customers);
    }
}
