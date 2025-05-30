<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Preset;
use App\Model\JsonResponse;
use App\Model\Response;
use App\Response\Error;
use App\Service\Admin\PresetService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Controller for preset management
 */
class PresetController extends BaseController
{
    /**
     * @var PresetService
     */
    private $presetService;

    /**
     * @required
     * @codeCoverageIgnore
     */
    public function setPresetService(PresetService $presetService): void
    {
        $this->presetService = $presetService;
    }

    /**
     * Returns the list of presets
     *
     * @Route("/presets", name="admin_get_presets", methods={"GET"})
     */
    public function getPresetsAction(Request $request): Response|JsonResponse
    {
        if (!$this->checkLogin($request)) {
            return $this->getFailedLoginResponse();
        }

        $presets = $this->presetService->getAllPresets();
        return new JsonResponse($presets);
    }

    /**
     * Creates or updates a preset
     *
     * @Route("/preset/save", name="admin_save_preset", methods={"POST"})
     */
    public function savePresetAction(Request $request): Response|Error|JsonResponse
    {
        if (false === $this->isPl($request)) {
            return $this->getFailedAuthorizationResponse();
        }

        try {
            $userId = $this->getUserId($request);

            $data = [
                'id' => $request->get('id'),
                'name' => $request->get('name'),
                'customer' => $request->get('customer'),
                'project' => $request->get('project'),
                'activity' => $request->get('activity'),
                'ticket' => $request->get('ticket'),
                'description' => $request->get('description'),
                'userId' => $userId
            ];

            $result = $this->presetService->savePreset($data);

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
     * Deletes a preset
     *
     * @Route("/preset/delete", name="admin_delete_preset", methods={"POST"})
     */
    public function deletePresetAction(Request $request): Response|Error|JsonResponse
    {
        if (false === $this->isPl($request)) {
            return $this->getFailedAuthorizationResponse();
        }

        try {
            $presetId = (int)$request->get('id');
            $userId = $this->getUserId($request);

            $result = $this->presetService->deletePreset($presetId, $userId);

            if (isset($result['error'])) {
                return new Error($this->translate($result['error']), 406);
            }

            return new JsonResponse(['success' => true]);
        } catch (\Exception $e) {
            return new Error($e->getMessage(), 500);
        }
    }
}
