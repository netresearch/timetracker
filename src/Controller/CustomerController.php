<?php

declare(strict_types=1);

namespace App\Controller;

use App\Model\JsonResponse;
use App\Model\Response;
use App\Response\Error;
use App\Service\Admin\CustomerService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Controller for customer management.
 */
class CustomerController extends BaseController
{
    /**
     * @var CustomerService
     */
    private $customerService;

    /**
     * @required
     * @codeCoverageIgnore
     */
    public function setCustomerService(CustomerService $customerService): void
    {
        $this->customerService = $customerService;
    }

    /**
     * Get all customers.
     *
     * @Route("/getAllCustomers", name="customer_get_all", methods={"GET"})
     */
    public function getCustomersAction(Request $request): Response|JsonResponse
    {
        if (!$this->checkLogin($request)) {
            return $this->getFailedLoginResponse();
        }

        return new JsonResponse($this->customerService->getAllCustomers());
    }

    /**
     * Save a customer.
     *
     * @Route("/customer/save", name="customer_save", methods={"POST"})
     */
    public function saveCustomerAction(Request $request): Response|Error|JsonResponse
    {
        if (false === $this->isPl($request)) {
            return $this->getFailedAuthorizationResponse();
        }

        try {
            // Extract data from request
            $customerData = [
                'id' => $request->get('id'),
                'name' => $request->get('name'),
                'active' => $request->get('active'),
                'global' => $request->get('global'),
                'teams' => $request->get('teams') ?: []
            ];

            // Save the customer
            $result = $this->customerService->saveCustomer($customerData);

            return new JsonResponse([
                $result['id'],
                $result['name'],
                $result['active'],
                $result['global'],
                $result['teams']
            ]);
        } catch (\Exception $e) {
            if ($e->getCode() == 404) {
                return new Error($e->getMessage(), 404);
            }

            $response = new Response($e->getMessage());
            $response->setStatusCode($e->getCode() ?: 406);
            return $response;
        }
    }

    /**
     * Delete a customer.
     *
     * @Route("/customer/delete", name="customer_delete", methods={"POST"})
     */
    public function deleteCustomerAction(Request $request): Response|Error|JsonResponse
    {
        if (false === $this->isPl($request)) {
            return $this->getFailedAuthorizationResponse();
        }

        try {
            $id = (int) $request->get('id');
            $this->customerService->deleteCustomer($id);

            return new JsonResponse(['success' => true]);
        } catch (\Exception $exception) {
            return new Error($exception->getMessage(), $exception->getCode() ?: 422);
        }
    }
}
