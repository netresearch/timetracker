<?php
declare(strict_types=1);

namespace App\Controller\Status;

use App\Controller\BaseController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class PageAction extends BaseController
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

    #[\Symfony\Component\Routing\Attribute\Route(path: '/status/page', name: 'check_page', methods: ['GET'])]
    public function __invoke(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        $login = $this->isLoggedIn($request);
        if (null === $request) {
            $req = $this->requestStack->getCurrentRequest();
            if (null !== $req) {
                $login = $this->isLoggedIn($req);
            } else {
                $login = $this->security->isGranted('IS_AUTHENTICATED');
            }
        }

        return $this->render('status.html.twig', [
            'loginClass' => ($login ? 'status_active' : 'status_inactive'),
            'apptitle' => $this->params->get('app_title'),
        ]);
    }
}


