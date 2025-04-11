<?php

declare(strict_types=1);

namespace App\Controller;

use App\Model\JsonResponse;
use App\Model\Response;
use App\Response\Error;
use App\Service\Admin\UserService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Controller for user management.
 */
class UserController extends BaseController
{
    /**
     * @var UserService
     */
    private $userService;

    /**
     * @required
     * @codeCoverageIgnore
     */
    public function setUserService(UserService $userService): void
    {
        $this->userService = $userService;
    }

    /**
     * Get all users.
     *
     * @Route("/getAllUsers", name="user_get_all", methods={"GET"})
     */
    public function getUsersAction(Request $request): Response|JsonResponse
    {
        if (!$this->checkLogin($request)) {
            return $this->getFailedLoginResponse();
        }

        return new JsonResponse($this->userService->getAllUsers());
    }

    /**
     * Save a user.
     *
     * @Route("/user/save", name="user_save", methods={"POST"})
     */
    public function saveUserAction(Request $request): Response|Error|JsonResponse
    {
        if (false === $this->isPl($request)) {
            return $this->getFailedAuthorizationResponse();
        }

        try {
            // Extract data from request
            $userData = [
                'id' => $request->get('id'),
                'username' => $request->get('username'),
                'abbr' => $request->get('abbr'),
                'type' => $request->get('type'),
                'locale' => $request->get('locale'),
                'teams' => $request->get('teams') ?: []
            ];

            // Save the user
            $result = $this->userService->saveUser($userData);

            return new JsonResponse([
                $result['id'],
                $result['username'],
                $result['abbr'],
                $result['type']
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
     * Delete a user.
     *
     * @Route("/user/delete", name="user_delete", methods={"POST"})
     */
    public function deleteUserAction(Request $request): Response|Error|JsonResponse
    {
        if (false === $this->isPl($request)) {
            return $this->getFailedAuthorizationResponse();
        }

        try {
            $id = (int) $request->get('id');
            $this->userService->deleteUser($id);

            return new JsonResponse(['success' => true]);
        } catch (\Exception $exception) {
            return new Error($exception->getMessage(), $exception->getCode() ?: 422);
        }
    }
}
