<?php declare(strict_types=1);
/**
 * basic controller to share some features with the child controllers.
 *
 * PHP version 8
 *
 * @category  Controller
 *
 * @author    Mathias Lieber <mathias.lieber@netresearch.de>
 * @copyright 2012 Netresearch App Factory AG
 * @license   No license
 *
 * @see      http://www.netresearch.de
 */

namespace App\Controller;

use App\Entity\User;
use App\Model\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Class BaseController.
 *
 * @category Controller
 *
 * @author   Mathias Lieber <mathias.lieber@netresearch.de>
 * @license  No license
 *
 * @see     http://www.netresearch.de
 */
class BaseController extends AbstractController
{
    protected Request $request;

    public function __construct(
        protected ManagerRegistry $doctrine,
        protected RequestStack $requestStack,
        protected TranslatorInterface $translator,
        protected ParameterBagInterface $params
    ) {
        $this->request = $requestStack->getCurrentRequest();
    }

    protected function getWorkUser(): ?User
    {
        return $this->doctrine->getRepository('App:User')
            ->findOneBy(['username' => $this->getUser()->getUserIdentifier()]);
    }

    protected function getUserId(): ?int
    {
        return $this->getWorkUser()?->getId();
    }

    /**
     * Returns a custom error message.
     */
    protected function getFailedResponse(string $message, int $status): Response
    {
        $response = new Response($message);
        $response->setStatusCode($status);

        return $response;
    }

    protected function t(
        string $id,
        array $parameters = [],
        string $domain = 'messages',
        string $locale = null
    ): mixed {
        $locale ??= $this->translator->getLocale();

        return $this->translator->trans($id, $parameters, $domain, $locale);
    }
}
