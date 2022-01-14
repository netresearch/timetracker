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
use App\Repository\ActivityRepository;
use App\Repository\ContractRepository;
use App\Repository\CustomerRepository;
use App\Repository\EntryRepository;
use App\Repository\HolidayRepository;
use App\Repository\PresetRepository;
use App\Repository\ProjectRepository;
use App\Repository\TeamRepository;
use App\Repository\TicketSystemRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
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
 * @author     Mathias Lieber <mathias.lieber@netresearch.de>
 * @license    No license
 * @deprecated Avoid using BaseController
 *
 * @see     http://www.netresearch.de
 */
class BaseController extends AbstractController
{
    protected Request $request;

    public function __construct(
        protected RequestStack $requestStack,
        protected TranslatorInterface $translator,
        protected ParameterBagInterface $params,
        protected UserRepository $userRepo,
        protected EntryRepository $entryRepo,
        protected CustomerRepository $customerRepo,
        protected ProjectRepository $projectRepo,
        protected ActivityRepository $activityRepo,
        protected HolidayRepository $holidayRepo,
        protected TicketSystemRepository $ticketSystemRepo,
        protected TeamRepository $teamRepo,
        protected PresetRepository $presetRepo,
        protected ContractRepository $contractRepo,
        protected EntityManagerInterface $em
    ) {
        $this->request = $requestStack->getCurrentRequest();
    }

    protected function getWorkUser(): ?User
    {
        return $this->userRepo->findOneBy(['username' => $this->getUser()->getUserIdentifier()]);
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
