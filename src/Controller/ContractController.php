<?php

declare(strict_types=1);

namespace App\Controller;

use App\Model\JsonResponse as ModelJsonResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use App\Model\Response;
use App\Response\Error;
use App\Service\Admin\ContractService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Controller for contract management
 */
class ContractController extends BaseController
{
    /**
     * @var ContractService
     */
    private $contractService;

    /**
     * @required
     * @codeCoverageIgnore
     */
    public function setContractService(ContractService $contractService): void
    {
        $this->contractService = $contractService;
    }

    /**
     * Returns the list of contracts
     *
     * @Route("/contracts", name="admin_get_contracts", methods={"GET"})
     */
    public function getContractsAction(Request $request): Response|JsonResponse
    {
        if (!$this->checkLogin($request)) {
            return $this->getFailedLoginResponse();
        }
        $contracts = $this->contractService->getAllContracts();
        return new JsonResponse($contracts);
    }

    /**
     * Creates or updates a contract
     *
     * @Route("/contract/save", name="admin_save_contract", methods={"POST"})
     */
    public function saveContractAction(Request $request): Response|Error|JsonResponse
    {
        if (false === $this->isPl($request)) {
            return $this->getFailedAuthorizationResponse();
        }

        try {
            $data = [
                'id' => $request->get('id'),
                'user_id' => $request->get('user_id'),
                'start' => $request->get('start'),
                'end' => $request->get('end'),
                'weeklyHours' => $request->get('weekly_hours'),
                'weeklyDays' => $request->get('weekly_days'),
                'dailyStartTime' => $request->get('daily_start_time'),
                'dailyEndTime' => $request->get('daily_end_time'),
                'vacationDays' => $request->get('vacation_days'),
                'notes' => $request->get('notes')
            ];

            $result = $this->contractService->saveContract($data);

            if (isset($result['error'])) {
                $response = new Response($this->translate($result['error']));
                $response->setStatusCode(406);
                return $response;
            }

            return new JsonResponse(['success' => true]);
        } catch (\Exception $e) {
            return new Error($e->getMessage(), 500);
        }
    }

    /**
     * Deletes a contract
     *
     * @Route("/contract/delete", name="admin_delete_contract", methods={"POST"})
     */
    public function deleteContractAction(Request $request): Response|Error|JsonResponse
    {
        if (false === $this->isPl($request)) {
            return $this->getFailedAuthorizationResponse();
        }

        try {
            $contractId = (int)$request->get('id');

            $result = $this->contractService->deleteContract($contractId);

            if (isset($result['error'])) {
                return new Error($this->translate($result['error']), 406);
            }

            return new JsonResponse(['success' => true]);
        } catch (\Exception $exception) {
            $reason = '';
            if (str_contains($exception->getMessage(), 'Integrity constraint violation')) {
                $reason = $this->translate('Other datasets refer to this one.');
            }

            $msg = sprintf($this->translate('Dataset could not be removed. %s'), $reason);
            return new Error($msg, 422);
        }
    }
}
