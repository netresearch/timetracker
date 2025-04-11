<?php

declare(strict_types=1);

namespace App\Controller;

use App\Model\JsonResponse;
use App\Model\Response;
use App\Response\Error;
use App\Service\Admin\ProjectService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Controller for project management.
 */
class ProjectController extends BaseController
{
    /**
     * @var ProjectService
     */
    private $projectService;

    /**
     * @required
     * @codeCoverageIgnore
     */
    public function setProjectService(ProjectService $projectService): void
    {
        $this->projectService = $projectService;
    }

    /**
     * Get all projects.
     *
     * @Route("/getAllProjects", name="project_get_all", methods={"GET"})
     */
    public function getAllProjectsAction(Request $request): Response|JsonResponse
    {
        if (!$this->checkLogin($request)) {
            return $this->getFailedLoginResponse();
        }

        $customerId = (int) $request->query->get('customer');

        // Use the repository directly to maintain the expected format
        $managerRegistry = $this->getDoctrine();
        /** @var \App\Repository\ProjectRepository $objectRepository */
        $objectRepository = $managerRegistry->getRepository(\App\Entity\Project::class);
        $result = $customerId > 0 ? $objectRepository->findByCustomer($customerId) : $objectRepository->findAll();

        $data = [];
        foreach ($result as $project) {
            $data[] = ['project' => $project->toArray()];
        }

        return new JsonResponse($data);
    }

    /**
     * Save a project.
     *
     * @Route("/project/save", name="project_save", methods={"POST"})
     */
    public function saveProjectAction(Request $request): Response|JsonResponse|Error
    {
        if (false === $this->isPl($request)) {
            return $this->getFailedAuthorizationResponse();
        }

        try {
            // Extract data from request
            $projectData = [
                'id' => $request->get('id'),
                'name' => $request->get('name'),
                'customer' => $request->get('customer'),
                'ticket_system' => $request->get('ticket_system'),
                'project_lead' => $request->get('project_lead'),
                'technical_lead' => $request->get('technical_lead'),
                'jiraId' => $request->get('jiraId'),
                'jiraTicket' => $request->get('jiraTicket'),
                'active' => $request->get('active'),
                'global' => $request->get('global'),
                'estimation' => $request->get('estimation'),
                'billing' => $request->get('billing'),
                'cost_center' => $request->get('cost_center'),
                'offer' => $request->get('offer'),
                'additionalInformationFromExternal' => $request->get('additionalInformationFromExternal'),
                'internalJiraTicketSystem' => $request->get('internalJiraTicketSystem'),
                'internalJiraProjectKey' => $request->get('internalJiraProjectKey')
            ];

            // Save the project
            $result = $this->projectService->saveProject($projectData, true);

            return new JsonResponse($result);
        } catch (\Exception $e) {
            // Return a proper error response with the exception message
            if ($e->getCode() >= 400) {
                return new Error($e->getMessage(), $e->getCode());
            }

            $response = new Response($e->getMessage());
            $response->setStatusCode(406);
            return $response;
        }
    }

    /**
     * Delete a project.
     *
     * @Route("/project/delete", name="project_delete", methods={"POST"})
     */
    public function deleteProjectAction(Request $request): Response|Error|JsonResponse
    {
        if (false === $this->isPl($request)) {
            return $this->getFailedAuthorizationResponse();
        }

        try {
            $id = (int) $request->get('id');
            $this->projectService->deleteProject($id);

            return new JsonResponse(['success' => true]);
        } catch (\Exception $exception) {
            return new Error($exception->getMessage(), $exception->getCode() ?: 422);
        }
    }

    /**
     * Sync all project subtickets.
     *
     * @Route("/projects/syncsubtickets", name="project_sync_all_subtickets", methods={"GET"})
     */
    public function syncAllProjectSubticketsAction(Request $request): Response|JsonResponse|Error
    {
        if (!$this->checkLogin($request)) {
            return $this->getFailedLoginResponse();
        }

        try {
            $this->projectService->syncAllProjectSubtickets();

            return new JsonResponse([
                'success' => true
            ]);
        } catch (\Exception $exception) {
            return new Error($exception->getMessage(), $exception->getCode() ?: 500);
        }
    }

    /**
     * Sync subtickets for a specific project.
     *
     * @Route("/projects/{project}/syncsubtickets", name="project_sync_subtickets", methods={"GET"})
     */
    public function syncProjectSubticketsAction(Request $request): Response|JsonResponse|Error
    {
        if (!$this->checkLogin($request)) {
            return $this->getFailedLoginResponse();
        }

        try {
            $projectId = (int) $request->get('project');
            $subtickets = $this->projectService->syncProjectSubtickets($projectId);

            return new JsonResponse([
                'success' => true,
                'subtickets' => $subtickets
            ]);
        } catch (\Exception $exception) {
            return new Error($exception->getMessage(), $exception->getCode() ?: 500);
        }
    }
}
