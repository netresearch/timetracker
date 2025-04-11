<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Activity;
use App\Model\JsonResponse;
use App\Model\Response;
use App\Response\Error;
use App\Service\Admin\ActivityService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Controller for activity management
 */
class ActivityController extends BaseController
{
    /**
     * @var ActivityService
     */
    private $activityService;

    /**
     * @required
     * @codeCoverageIgnore
     */
    public function setActivityService(ActivityService $activityService): void
    {
        $this->activityService = $activityService;
    }

    /**
     * Creates or updates an activity
     *
     * @Route("/admin/activity/save", name="admin_save_activity", methods={"POST"})
     */
    public function saveActivityAction(Request $request): Response|Error|JsonResponse
    {
        if (false === $this->isPl($request)) {
            return $this->getFailedAuthorizationResponse();
        }

        try {
            $data = [
                'id' => $request->get('id'),
                'name' => $request->get('name'),
                'active' => $request->get('active'),
                'billable' => $request->get('billable'),
                'global' => $request->get('global')
            ];

            $result = $this->activityService->saveActivity($data);

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
     * Deletes an activity
     *
     * @Route("/admin/activity/delete", name="admin_delete_activity", methods={"POST"})
     */
    public function deleteActivityAction(Request $request): Response|Error|JsonResponse
    {
        if (false === $this->isPl($request)) {
            return $this->getFailedAuthorizationResponse();
        }

        try {
            $activityId = (int)$request->get('id');

            $result = $this->activityService->deleteActivity($activityId);

            if (isset($result['error'])) {
                return new Error($this->translate($result['error']), 406);
            }

            return new JsonResponse(['success' => true]);
        } catch (\Exception $e) {
            return new Error($e->getMessage(), 500);
        }
    }
}
