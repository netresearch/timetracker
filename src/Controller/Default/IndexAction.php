<?php
declare(strict_types=1);

namespace App\Controller\Default;

use App\Controller\BaseController;
use App\Entity\Customer;
use App\Entity\Project;
use App\Entity\User;
use Symfony\Component\HttpFoundation\Request;

final class IndexAction extends BaseController
{
    #[\Symfony\Component\Routing\Attribute\Route(path: '/', name: '_start', methods: ['GET'])]
    public function __invoke(Request $request): \Symfony\Component\HttpFoundation\RedirectResponse|\App\Model\Response|\Symfony\Component\HttpFoundation\Response
    {
        if (!$this->checkLogin($request)) {
            return $this->login($request);
        }

        $userId = $this->getUserId($request);
        $managerRegistry = $this->managerRegistry;

        $user = $managerRegistry->getRepository(User::class)->find($userId);
        if (!$user instanceof User) {
            return $this->getFailedLoginResponse();
        }

        $settings = $user->getSettings();

        /** @var \App\Repository\CustomerRepository $objectRepository */
        $objectRepository = $managerRegistry->getRepository(Customer::class);
        $customers = $objectRepository->getCustomersByUser($userId);

        /** @var \App\Repository\ProjectRepository $projectRepo */
        $projectRepo = $managerRegistry->getRepository(Project::class);
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


