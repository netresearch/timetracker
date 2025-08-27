<?php
declare(strict_types=1);

namespace App\Controller\Interpretation;

use App\Controller\BaseController;
use App\Model\JsonResponse;
use App\Model\Response as ModelResponse;
use App\Service\Util\TimeCalculationService;
use Symfony\Component\HttpFoundation\Request;

final class GroupByCustomerAction extends BaseController
{
    private TimeCalculationService $timeCalculationService;

    #[\Symfony\Contracts\Service\Attribute\Required]
    public function setTimeCalculationService(TimeCalculationService $timeCalculationService): void
    {
        $this->timeCalculationService = $timeCalculationService;
    }

    #[\Symfony\Component\Routing\Attribute\Route(path: '/interpretation/customer', name: 'interpretation_customer_attr', methods: ['GET'])]
    public function __invoke(Request $request): ModelResponse|JsonResponse
    {
        if (!$this->checkLogin($request)) {
            return $this->getFailedLoginResponse();
        }

        try {
            /** @var \App\Repository\EntryRepository $repo */
            $repo = $this->managerRegistry->getRepository(\App\Entity\Entry::class);
            $entries = $repo->findByFilterArray(['user' => $this->getUserId($request)]);
        } catch (\Exception $exception) {
            $response = new ModelResponse($this->translate($exception->getMessage()));
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);
            return $response;
        }

        $customers = [];
        foreach ($entries as $entry) {
            $customerEntity = $entry->getCustomer();
            if (!$customerEntity) { continue; }
            $cid = $customerEntity->getId();
            if (!isset($customers[$cid])) {
                $customers[$cid] = ['id' => $cid, 'name' => $customerEntity->getName(), 'hours' => 0, 'quota' => 0];
            }
            $customers[$cid]['hours'] += $entry->getDuration() / 60;
        }

        $sum = 0; foreach ($customers as $c) { $sum += $c['hours']; }
        foreach ($customers as &$c) { $c['quota'] = $this->timeCalculationService->formatQuota($c['hours'], $sum); }
        usort($customers, static fn($a, $b) => strcmp((string) $b['name'], (string) $a['name']));

        return new JsonResponse(array_values($customers));
    }
}


