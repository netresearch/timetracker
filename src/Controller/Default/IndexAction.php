<?php

declare(strict_types=1);

namespace App\Controller\Default;

use App\Controller\BaseController;
use App\Entity\Customer;
use App\Entity\Project;
use App\Entity\User;
use App\Model\Response;
use App\Repository\CustomerRepository;
use App\Repository\ProjectRepository;
use Exception;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;

use function assert;

final class IndexAction extends BaseController
{
    /**
     * @throws Exception    When database operations fail
     * @throws LoaderError  When template loading fails
     * @throws RuntimeError When template rendering fails
     * @throws SyntaxError  When template syntax is invalid
     */
    #[Route(path: '/', name: '_start', methods: ['GET'])]
    public function __invoke(#[CurrentUser] ?User $user = null): RedirectResponse|Response|\Symfony\Component\HttpFoundation\Response
    {
        if (!$user instanceof User) {
            return $this->redirectToRoute('_login');
        }

        $userId = (int) $user->getId();

        $settings = $user->getSettings();

        $objectRepository = $this->managerRegistry->getRepository(Customer::class);
        assert($objectRepository instanceof CustomerRepository);
        $customers = $objectRepository->getCustomersByUser($userId);

        $projectRepo = $this->managerRegistry->getRepository(Project::class);
        assert($projectRepo instanceof ProjectRepository);
        $projects = $projectRepo->getProjectStructure($userId, $customers);

        return $this->render('index.html.twig', [
            'globalConfig' => [
                'logo_url' => $this->params->get('app_logo_url'),
                'monthly_overview_url' => $this->params->get('app_monthly_overview_url'),
                'header_url' => $this->params->get('app_header_url'),
            ],
            'apptitle' => $this->params->get('app_title'),
            'environment' => $this->kernel->getEnvironment(),
            'customers' => $customers,
            'projects' => $projects,
            'settings' => $settings,
            'locale' => $settings['locale'],
        ]);
    }
}
