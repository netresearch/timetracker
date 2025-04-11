<?php

declare(strict_types=1);

namespace App\Controller;

use App\Model\JsonResponse;
use App\Model\Response;
use App\Response\Error;
use App\Service\Admin\TeamService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Controller for team management.
 */
class TeamController extends BaseController
{
    /**
     * @var TeamService
     */
    private $teamService;

    /**
     * @required
     * @codeCoverageIgnore
     */
    public function setTeamService(TeamService $teamService): void
    {
        $this->teamService = $teamService;
    }

    /**
     * Get all teams.
     *
     * @Route("/getAllTeams", name="team_get_all", methods={"GET"})
     */
    public function getTeamsAction(Request $request): Response|JsonResponse
    {
        if (!$this->checkLogin($request)) {
            return $this->getFailedLoginResponse();
        }

        return new JsonResponse($this->teamService->getAllTeams());
    }

    /**
     * Save a team.
     *
     * @Route("/team/save", name="team_save", methods={"POST"})
     */
    public function saveTeamAction(Request $request): Response|Error|JsonResponse
    {
        if (!$this->checkLogin($request)) {
            return $this->getFailedLoginResponse();
        }

        if (false === $this->isPl($request)) {
            return $this->getFailedAuthorizationResponse();
        }

        try {
            // Extract data from request
            $teamData = [
                'id' => $request->get('id'),
                'name' => $request->get('name'),
                'lead_user_id' => $request->get('lead_user_id')
            ];

            // Save the team
            $result = $this->teamService->saveTeam($teamData);

            return new JsonResponse([
                $result['id'],
                $result['name'],
                $result['lead_user_id']
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
     * Delete a team.
     *
     * @Route("/team/delete", name="team_delete", methods={"POST"})
     */
    public function deleteTeamAction(Request $request): Response|Error|JsonResponse
    {
        if (false === $this->isPl($request)) {
            return $this->getFailedAuthorizationResponse();
        }

        try {
            $id = (int) $request->get('id');
            $this->teamService->deleteTeam($id);

            return new JsonResponse(['success' => true]);
        } catch (\Exception $exception) {
            return new Error($exception->getMessage(), $exception->getCode() ?: 422);
        }
    }
}
