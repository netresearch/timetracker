<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\TicketSystem;
use App\Model\JsonResponse;
use App\Model\Response;
use App\Response\Error;
use App\Service\Admin\TicketSystemService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Controller for ticket system management
 */
class TicketSystemController extends BaseController
{
    /**
     * @var TicketSystemService
     */
    private $ticketSystemService;

    /**
     * @required
     * @codeCoverageIgnore
     */
    public function setTicketSystemService(TicketSystemService $ticketSystemService): void
    {
        $this->ticketSystemService = $ticketSystemService;
    }

    /**
     * Returns the list of ticket systems
     *
     * @Route("/admin/ticketsystems", name="admin_get_ticket_systems", methods={"GET"})
     */
    public function getTicketSystemsAction(Request $request): Response|JsonResponse
    {
        if (!$this->checkLogin($request)) {
            return $this->getFailedLoginResponse();
        }

        $isPl = $this->isPl($request);
        $ticketSystems = $this->ticketSystemService->getAllTicketSystems($isPl);

        return new JsonResponse($ticketSystems);
    }

    /**
     * Creates or updates a ticket system
     *
     * @Route("/admin/ticketsystem/save", name="admin_save_ticket_system", methods={"POST"})
     */
    public function saveTicketSystemAction(Request $request): Response|Error|JsonResponse
    {
        if (false === $this->isPl($request)) {
            return $this->getFailedAuthorizationResponse();
        }

        try {
            $data = [
                'id' => $request->get('id'),
                'name' => $request->get('name'),
                'type' => $request->get('type'),
                'url' => $request->get('url'),
                'login' => $request->get('login'),
                'password' => $request->get('password'),
                'oauthEnabled' => $request->get('oauthEnabled'),
                'privateKey' => $request->get('privateKey'),
                'publicKey' => $request->get('publicKey'),
                'oauthConsumerKey' => $request->get('oauthConsumerKey'),
                'oauthConsumerSecret' => $request->get('oauthConsumerSecret'),
                'projectMappingField' => $request->get('projectMappingField'),
                'active' => $request->get('active')
            ];

            $result = $this->ticketSystemService->saveTicketSystem($data);

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
     * Deletes a ticket system
     *
     * @Route("/admin/ticketsystem/delete", name="admin_delete_ticket_system", methods={"POST"})
     */
    public function deleteTicketSystemAction(Request $request): Response|Error|JsonResponse
    {
        if (false === $this->isPl($request)) {
            return $this->getFailedAuthorizationResponse();
        }

        try {
            $ticketSystemId = (int)$request->get('id');

            $result = $this->ticketSystemService->deleteTicketSystem($ticketSystemId);

            if (isset($result['error'])) {
                return new Error($this->translate($result['error']), 406);
            }

            return new JsonResponse(['success' => true]);
        } catch (\Exception $e) {
            return new Error($e->getMessage(), 500);
        }
    }
}
