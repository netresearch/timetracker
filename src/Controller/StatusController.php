<?php

namespace App\Controller;

use App\Model\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;

class StatusController extends BaseController
{
    private \Symfony\Bundle\SecurityBundle\Security $security;
    private RequestStack $requestStack;

    #[\Symfony\Contracts\Service\Attribute\Required]
    public function setSecurity(\Symfony\Bundle\SecurityBundle\Security $security): void
    {
        $this->security = $security;
    }

    #[\Symfony\Contracts\Service\Attribute\Required]
    public function setRequestStack(RequestStack $requestStack): void
    {
        $this->requestStack = $requestStack;
    }

    /**
     * @return bool[]
     *
     * @psalm-return array{loginStatus: bool}
     */
    private function getStatus(): array
    {
        $request = isset($this->requestStack) ? $this->requestStack->getCurrentRequest() : null;
        $login = false;
        if (null !== $request) {
            // Prefer BaseController logic which supports test session fallback
            $login = $this->isLoggedIn($request);
        } else {
            // Fallback to security service if no request could be resolved
            $login = $this->security->isGranted('IS_AUTHENTICATED');
        }

        return [
            'loginStatus' => $login,
        ];
    }

    #[\Symfony\Component\Routing\Attribute\Route(path: '/status/check', name: 'check_status', methods: ['GET'])]
    public function check(): JsonResponse
    {
        return new JsonResponse($this->getStatus());
    }

    #[\Symfony\Component\Routing\Attribute\Route(path: '/status/page', name: 'check_page', methods: ['GET'])]
    public function page(): \Symfony\Component\HttpFoundation\Response
    {
        $status = $this->getStatus();

        return $this->render('status.html.twig', [
            'loginClass' => ($status['loginStatus'] ? 'status_active' : 'status_inactive'),
            'apptitle' => $this->params->get('app_title'),
        ]);
    }
}
