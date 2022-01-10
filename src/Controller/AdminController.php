<?php declare(strict_types=1);

namespace App\Controller;

use ReflectionException;
use Exception;
use DateTime;
use DateInterval;
use App\Model\Response;
use App\Entity\Contract;
use App\Entity\Team;
use App\Helper\JiraOAuthApi;
use App\Response\Error;
use App\Entity\Project;
use App\Entity\Customer;
use App\Entity\User;
use App\Entity\Preset;
use App\Entity\TicketSystem;
use App\Entity\Activity;
use App\Helper\TimeHelper;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Class AdminController.
 */
class AdminController extends BaseController
{
    public function getAllProjectsAction(): Response
    {
        if (!$this->checkLogin()) {
            return $this->getFailedLoginResponse();
        }

        $result = $this->doctrine->getRepository('App:Project')->findAll();

        $data = [];
        foreach ($result as $project) {
            $data[] = ['project' => $project->toArray()];
        }

        return new Response(json_encode($data, \JSON_THROW_ON_ERROR));
    }

    #[Route(path: '/getAllCustomers', name: '_getAllCustomers')]
    public function getCustomersAction(): Response
    {
        if (!$this->checkLogin()) {
            return $this->getFailedLoginResponse();
        }

        /** @var \App\Repository\CustomerRepository $repo */
        $repo = $this->doctrine->getRepository('App:Customer');

        return new Response(json_encode($repo->getAllCustomers(), \JSON_THROW_ON_ERROR));
    }

    #[Route(path: '/getAllUsers', name: '_getAllUsers')]
    public function getUsersAction(): Response
    {
        if (!$this->checkLogin()) {
            return $this->getFailedLoginResponse();
        }

        /** @var \App\Repository\UserRepository $repo */
        $repo = $this->doctrine->getRepository('App:User');

        return new Response(json_encode($repo->getAllUsers(), \JSON_THROW_ON_ERROR));
    }

    #[Route(path: '/getAllTeams', name: '_getAllTeams')]
    public function getTeamsAction(): Response
    {
        if (!$this->checkLogin()) {
            return $this->getFailedLoginResponse();
        }

        /** @var \App\Repository\TeamRepository $repo */
        $repo = $this->doctrine->getRepository('App:Team');

        return new Response(json_encode($repo->findAll(), \JSON_THROW_ON_ERROR));
    }

    #[Route(path: '/getAllPresets', name: '_getAllPresets')]
    public function getPresetsAction(): Response
    {
        if (!$this->checkLogin()) {
            return $this->getFailedLoginResponse();
        }

        /** @var \App\Repository\PresetRepository $repo */
        $repo = $this->doctrine->getRepository('App:Preset');

        return new Response(json_encode($repo->getAllPresets(), \JSON_THROW_ON_ERROR));
    }

    /**
     * @throws ReflectionException
     */
    #[Route(path: '/getTicketSystems', name: '_getTicketSystems')]
    public function getTicketSystemsAction(): Response
    {
        if (!$this->checkLogin()) {
            return $this->getFailedLoginResponse();
        }

        /** @var \App\Repository\TicketSystemRepository $repo */
        $repo          = $this->doctrine->getRepository('App:TicketSystem');
        $ticketSystems = $repo->getAllTicketSystems();

        if (false === $this->isPl()) {
            $c = is_countable($ticketSystems) ? \count($ticketSystems) : 0;
            for ($i = 0; $i < $c; ++$i) {
                unset($ticketSystems[$i]['ticketSystem']['login'], $ticketSystems[$i]['ticketSystem']['password'], $ticketSystems[$i]['ticketSystem']['publicKey'], $ticketSystems[$i]['ticketSystem']['privateKey'], $ticketSystems[$i]['ticketSystem']['oauthConsumerSecret'], $ticketSystems[$i]['ticketSystem']['oauthConsumerKey']);
            }
        }

        return new Response(json_encode($ticketSystems, \JSON_THROW_ON_ERROR));
    }

    #[Route(path: '/project/save')]
    public function saveProjectAction(): Response
    {
        if (false === $this->isPl()) {
            return $this->getFailedAuthorizationResponse();
        }

        $data      = null;
        $projectId = (int) $this->request->get('id');
        $name      = $this->request->get('name');

        /** @var TicketSystem $ticketSystem */
        $ticketSystem = $this->request->get('ticket_system') ?
            $this->doctrine
                ->getRepository('App:TicketSystem')
                ->find($this->request->get('ticket_system'))
            : null;

        $projectLead = $this->request->get('project_lead') ?
            $this->doctrine
                ->getRepository('App:User')
                ->find($this->request->get('project_lead'))
            : null;

        $technicalLead = $this->request->get('technical_lead') ?
            $this->doctrine
                ->getRepository('App:User')
                ->find($this->request->get('technical_lead'))
            : null;

        $jiraId                            = strtoupper($this->request->get('jiraId'));
        $active                            = $this->request->get('active') ?: 0;
        $global                            = $this->request->get('global') ?: 0;
        $estimation                        = TimeHelper::readable2minutes($this->request->get('estimation') ?: '0m');
        $billing                           = $this->request->get('billing') ?: 0;
        $costCenter                        = $this->request->get('cost_center') ?: null;
        $offer                             = $this->request->get('offer') ?: 0;
        $additionalInformationFromExternal = $this->request->get('additionalInformationFromExternal') ?: 0;
        /** @var \App\Repository\ProjectRepository $projectRepository */
        $projectRepository        = $this->doctrine->getRepository('App:Project');
        $internalJiraTicketSystem = (int) $this->request->get('internalJiraTicketSystem', 0);
        $internalJiraProjectKey   = $this->request->get('internalJiraProjectKey', 0);

        if ($projectId) {
            $project = $projectRepository->find($projectId);
        } else {
            $project = new Project();

            /** @var Customer $customer */
            $customer = $this->doctrine
                ->getRepository('App:Customer')
                ->find($this->request->get('customer'))
            ;

            if (!$customer) {
                $response = new Response($this->t('Please choose a customer.'));
                $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);

                return $response;
            }

            $project->setCustomer($customer);
        }

        if (\strlen($name) < 3) {
            $response = new Response($this->t('Please provide a valid project name with at least 3 letters.'));
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);

            return $response;
        }

        $sameNamedProject = $projectRepository->findOneBy(
            ['name' => $name, 'customer' => $project->getCustomer()->getId()]
        );
        if ($sameNamedProject) {
            if ($project->getId() !== $sameNamedProject->getId()) {
                $response = new Response($this->t('The project name provided already exists.'));
                $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);

                return $response;
            }
        }

        if ((1 < \strlen($jiraId)) && ($project->getJiraId() !== $jiraId)) {
            $search = ['jiraId' => $jiraId];
            if ($ticketSystem) {
                $search['ticketSystem'] = $ticketSystem;
            }
        }

        if (\strlen($jiraId) && false === $projectRepository->isValidJiraPrefix($jiraId)) {
            $response = new Response($this->t('Please provide a valid ticket prefix with only capital letters.'));
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);

            return $response;
        }

        $project
            ->setName($name)
            ->setTicketSystem($ticketSystem)
            ->setJiraId($jiraId)
            ->setActive($active)
            ->setGlobal($global)
            ->setEstimation($estimation)
            ->setProjectLead($projectLead)
            ->setTechnicalLead($technicalLead)
            ->setBilling($billing)
            ->setOffer($offer)
            ->setCostCenter($costCenter)
            ->setAdditionalInformationFromExternal($additionalInformationFromExternal)
            ->setInternalJiraProjectKey($internalJiraProjectKey)
            ->setInternalJiraTicketSystem($internalJiraTicketSystem)
        ;

        $em = $this->doctrine->getManager();
        $em->persist($project);
        $em->flush();

        $data = [$project->getId(), $name, $project->getCustomer()->getId(), $jiraId];

        return new Response(json_encode($data, \JSON_THROW_ON_ERROR));
    }

    #[Route(path: '/project/delete')]
    public function deleteProjectAction(): Error|Response
    {
        if (false === $this->isPl()) {
            return $this->getFailedAuthorizationResponse();
        }

        try {
            $id       = (int) $this->request->get('id');
            $doctrine = $this->doctrine;

            $project = $doctrine->getRepository('App:Project')
                ->find($id)
            ;

            $em = $doctrine->getManager();
            $em->remove($project);
            $em->flush();
        } catch (Exception $e) {
            $reason = '';
            if (str_contains($e->getMessage(), 'Integrity constraint violation')) {
                $reason = $this->t('Other datasets refer to this one.');
            }
            $msg = sprintf($this->t('Dataset could not be removed. %s'), $reason);

            return new Error($msg, 422);
        }

        return new Response(json_encode(['success' => true]));
    }

    #[Route(path: '/customer/save')]
    public function saveCustomerAction(): Response
    {
        if (false === $this->isPl()) {
            return $this->getFailedAuthorizationResponse();
        }

        $data       = null;
        $customerId = (int) $this->request->get('id');
        $name       = $this->request->get('name');
        $active     = $this->request->get('active') ?: 0;
        $global     = $this->request->get('global') ?: 0;
        $teamIds    = $this->request->get('teams') ?: [];

        $customerRepository = $this->doctrine->getRepository('App:Customer');

        if ($customerId) {
            $customer = $customerRepository->find($customerId);
        } else {
            $customer = new Customer();
        }

        if (\strlen($name) < 3) {
            $response = new Response($this->t('Please provide a valid customer name with at least 3 letters.'));
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);

            return $response;
        }

        if ($sameNamedCustomer = $customerRepository->findOneByName($name)) {
            if ($customer->getId() !== $sameNamedCustomer->getId()) {
                $response = new Response($this->t('The customer name provided already exists.'));
                $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);

                return $response;
            }
        }

        $customer->setName($name)->setActive($active)->setGlobal($global);

        $customer->resetTeams();

        foreach ($teamIds as $teamId) {
            if (!$teamId) {
                continue;
            }
            if ($team = $this->doctrine->getRepository('App:Team')->find((int) $teamId)) {
                $customer->addTeam($team);
            } else {
                $response = new Response(sprintf($this->t('Could not find team with ID %s.'), (int) $teamId));
                $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);

                return $response;
            }
        }

        if (0 === $customer->getTeams()->count() && false === $global) {
            $response = new Response($this->t('Every customer must belong to at least one team if it is not global.'));
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);

            return $response;
        }

        $em = $this->doctrine->getManager();
        $em->persist($customer);
        $em->flush();

        $data = [$customer->getId(), $name, $active, $global, $teamIds];

        return new Response(json_encode($data, \JSON_THROW_ON_ERROR));
    }

    #[Route(path: '/customer/delete')]
    public function deleteCustomerAction(): Error|Response
    {
        if (false === $this->isPl()) {
            return $this->getFailedAuthorizationResponse();
        }

        try {
            $id       = (int) $this->request->get('id');
            $doctrine = $this->doctrine;

            $customer = $doctrine->getRepository('App:Customer')
                ->find($id)
            ;

            $em = $doctrine->getManager();
            $em->remove($customer);
            $em->flush();
        } catch (Exception $e) {
            $reason = '';
            if (str_contains($e->getMessage(), 'Integrity constraint violation')) {
                $reason = $this->t('Other datasets refer to this one.');
            }
            $msg = sprintf($this->t('Dataset could not be removed. %s'), $reason);

            return new Error($msg, 422);
        }

        return new Response(json_encode(['success' => true]));
    }

    #[Route(path: '/user/save')]
    public function saveUserAction(): Response
    {
        if (false === $this->isPl()) {
            return $this->getFailedAuthorizationResponse();
        }

        $userId  = (int) $this->request->get('id');
        $name    = $this->request->get('username');
        $abbr    = $this->request->get('abbr');
        $type    = $this->request->get('type');
        $locale  = $this->request->get('locale');
        $teamIds = $this->request->get('teams') ?: [];

        /** @var UserRepository $userRepository */
        $userRepository = $this->doctrine->getRepository('App:User');

        if ($userId) {
            $user = $userRepository->find($userId);
        } else {
            $user = new User();
        }

        if (\strlen($name) < 3) {
            $response = new Response($this->t('Please provide a valid user name with at least 3 letters.'));
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);

            return $response;
        }

        if (\strlen($abbr) < 3) {
            $response = new Response($this->t('Please provide a valid user name abbreviation with at least 3 letters.'));
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);

            return $response;
        }

        if ($sameNamedUser = $userRepository->findOneByUsername($name)) {
            if ($user->getId() !== $sameNamedUser->getId()) {
                $response = new Response($this->t('The user name provided already exists.'));
                $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);

                return $response;
            }
        }

        if ($sameAbbrUser = $userRepository->findOneByAbbr($abbr)) {
            if ($user->getId() !== $sameAbbrUser->getId()) {
                $response = new Response($this->t('The user name abreviation provided already exists.'));
                $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);

                return $response;
            }
        }

        $user->setUsername($name)
            ->setAbbr($abbr)
            ->setLocale($locale)
            ->setType($type)
            ->setShowEmptyLine(0)
            ->setSuggestTime(1)
            ->setShowFuture(1)
        ;

        $user->resetTeams();

        foreach ($teamIds as $teamId) {
            if (!$teamId) {
                continue;
            }
            if ($team = $this->doctrine->getRepository('App:Team')->find((int) $teamId)) {
                $user->addTeam($team);
            } else {
                $response = new Response(sprintf($this->t('Could not find team with ID %s.'), (int) $teamId));
                $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);

                return $response;
            }
        }

        if (0 === $user->getTeams()->count()) {
            $response = new Response($this->t('Every user must belong to at least one team'));
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);

            return $response;
        }

        $em = $this->doctrine->getManager();
        $em->persist($user);
        $em->flush();

        $data = [$user->getId(), $name, $abbr, $type];

        return new Response(json_encode($data, \JSON_THROW_ON_ERROR));
    }

    #[Route(path: '/user/delete')]
    public function deleteUserAction(): Error|Response
    {
        if (false === $this->isPl()) {
            return $this->getFailedAuthorizationResponse();
        }

        try {
            $id       = (int) $this->request->get('id');
            $doctrine = $this->doctrine;

            $user = $doctrine->getRepository('App:User')
                ->find($id)
            ;

            $em = $doctrine->getManager();
            $em->remove($user);
            $em->flush();
        } catch (Exception $e) {
            $reason = '';
            if (str_contains($e->getMessage(), 'Integrity constraint violation')) {
                $reason = $this->t('Other datasets refer to this one.');
            }
            $msg = sprintf($this->t('Dataset could not be removed. %s'), $reason);

            return new Error($msg, 422);
        }

        return new Response(json_encode(['success' => true]));
    }

    #[Route(path: '/preset/delete')]
    public function deletePresetAction(): Error|Response
    {
        if (false === $this->isPl()) {
            return $this->getFailedAuthorizationResponse();
        }

        try {
            $id       = (int) $this->request->get('id');
            $doctrine = $this->doctrine;

            $preset = $doctrine->getRepository('App:Preset')
                ->find($id)
            ;

            $em = $doctrine->getManager();
            $em->remove($preset);
            $em->flush();
        } catch (Exception $e) {
            $reason = '';
            if (str_contains($e->getMessage(), 'Integrity constraint violation')) {
                $reason = $this->t('Other datasets refer to this one.');
            }
            $msg = sprintf($this->t('Dataset could not be removed. %s'), $reason);

            return new Error($msg, 422);
        }

        return new Response(json_encode(['success' => true]));
    }

    #[Route(path: '/preset/save')]
    public function savePresetAction(): Response
    {
        if (false === $this->isPl()) {
            return $this->getFailedAuthorizationResponse();
        }

        $id       = (int) $this->request->get('id');
        $name     = $this->request->get('name');
        $customer = $this->doctrine
            ->getRepository('App:Customer')
            ->find($this->request->get('customer'))
        ;
        $project = $this->doctrine
            ->getRepository('App:Project')
            ->find($this->request->get('project'))
        ;
        $activity = $this->doctrine
            ->getRepository('App:Activity')
            ->find($this->request->get('activity'))
        ;
        $description = $this->request->get('description');

        if (\strlen($name) < 3) {
            $response = new Response($this->t('Please provide a valid preset name with at least 3 letters.'));
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);

            return $response;
        }

        $repository = $this->doctrine->getRepository('App:Preset');

        if ($id) {
            $preset = $repository->find($id);
        } else {
            $preset = new Preset();
        }

        try {
            $preset->setName($name)
                ->setCustomer($customer)
                ->setProject($project)
                ->setActivity($activity)
                ->setDescription($description)
            ;

            $em = $this->doctrine->getManager();
            $em->persist($preset);
            $em->flush();
        } catch (Exception) {
            $response = new Response($this->t('Please choose a customer, a project and an activity.'));
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_FORBIDDEN);

            return $response;
        }

        return new Response(json_encode($preset->toArray(), \JSON_THROW_ON_ERROR));
    }

    /**
     * @throws ReflectionException
     */
    #[Route(path: '/ticketsystem/save')]
    public function saveTicketSystemAction(): Response
    {
        if (false === $this->isPl()) {
            return $this->getFailedAuthorizationResponse();
        }

        $repository = $this->doctrine->getRepository('App:TicketSystem');

        $id                  = (int) $this->request->get('id');
        $name                = $this->request->get('name');
        $type                = $this->request->get('type');
        $bookTime            = $this->request->get('bookTime');
        $url                 = $this->request->get('url');
        $login               = $this->request->get('login');
        $password            = $this->request->get('password');
        $publicKey           = $this->request->get('publicKey');
        $privateKey          = $this->request->get('privateKey');
        $ticketUrl           = $this->request->get('ticketUrl');
        $oauthConsumerKey    = $this->request->get('oauthConsumerKey');
        $oauthConsumerSecret = $this->request->get('oauthConsumerSecret');

        if ($id) {
            $ticketSystem = $repository->find($id);
        } else {
            $ticketSystem = new TicketSystem();
        }

        if (\strlen($name) < 3) {
            $response = new Response($this->t('Please provide a valid ticket system name with at least 3 letters.'));
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);

            return $response;
        }

        if ($sameNamedSystem = $repository->findOneByName($name)) {
            if ($ticketSystem->getId() !== $sameNamedSystem->getId()) {
                $response = new Response($this->t('The ticket system name provided already exists.'));
                $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);

                return $response;
            }
        }

        try {
            $ticketSystem
                ->setName($name)
                ->setType($type)
                ->setBookTime((bool) $bookTime)
                ->setUrl($url)
                ->setTicketUrl($ticketUrl)
                ->setLogin($login)
                ->setPassword($password)
                ->setPublicKey($publicKey)
                ->setPrivateKey($privateKey)
                ->setOauthConsumerKey($oauthConsumerKey)
                ->setOauthConsumerSecret($oauthConsumerSecret)
            ;

            $em = $this->doctrine->getManager();
            $em->persist($ticketSystem);
            $em->flush();
        } catch (Exception $e) {
            $response = new Response($this->t('Error on save').': '.$e->getMessage());
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_FORBIDDEN);

            return $response;
        }

        return new Response(json_encode($ticketSystem->toArray(), \JSON_THROW_ON_ERROR));
    }

    #[Route(path: '/ticketsystem/delete')]
    public function deleteTicketSystemAction(): Error|Response
    {
        if (false === $this->isPl()) {
            return $this->getFailedAuthorizationResponse();
        }

        try {
            $id       = (int) $this->request->get('id');
            $doctrine = $this->doctrine;

            $ticketSystem = $doctrine->getRepository('App:TicketSystem')
                ->find($id)
            ;

            $em = $doctrine->getManager();
            $em->remove($ticketSystem);
            $em->flush();
        } catch (Exception $e) {
            $reason = '';
            if (str_contains($e->getMessage(), 'Integrity constraint violation')) {
                $reason = $this->t('Other datasets refer to this one.');
            }
            $msg = sprintf($this->t('Dataset could not be removed. %s'), $reason);

            return new Error($msg, 422);
        }

        return new Response(json_encode(['success' => true]));
    }

    #[Route(path: '/activity/save')]
    public function saveActivityAction(): Response
    {
        if (!$this->checkLogin()) {
            return $this->getFailedLoginResponse();
        }

        if (false === $this->isPl()) {
            return $this->getFailedAuthorizationResponse();
        }

        $repository = $this->doctrine->getRepository('App:Activity');

        $id          = (int) $this->request->get('id');
        $name        = $this->request->get('name');
        $needsTicket = (bool) $this->request->get('needsTicket');
        $factor      = str_replace(',', '.', $this->request->get('factor'));

        if ($id) {
            $activity = $repository->find($id);
        } else {
            $activity = new Activity();
        }

        if ($sameNamedActivity = $repository->findOneByName($name)) {
            if ($activity->getId() !== $sameNamedActivity->getId()) {
                $response = new Response($this->t('The activity name provided already exists.'));
                $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);

                return $response;
            }
        }

        try {
            $activity
                ->setName($name)
                ->setNeedsTicket($needsTicket)
                ->setFactor($factor)
            ;

            $em = $this->doctrine->getManager();
            $em->persist($activity);
            $em->flush();
        } catch (Exception $e) {
            $response = new Response($this->t('Error on save').': '.$e->getMessage());
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_FORBIDDEN);

            return $response;
        }

        $data = [$activity->getId(), $activity->getName(), $activity->getNeedsTicket(), $activity->getFactor()];

        return new Response(json_encode($data, \JSON_THROW_ON_ERROR));
    }

    #[Route(path: '/activity/delete')]
    public function deleteActivityAction(): Error|Response
    {
        if (false === $this->isPl()) {
            return $this->getFailedAuthorizationResponse();
        }

        try {
            $id       = (int) $this->request->get('id');
            $doctrine = $this->doctrine;

            $activity = $doctrine->getRepository('App:Activity')
                ->find($id)
            ;

            $em = $doctrine->getManager();
            $em->remove($activity);
            $em->flush();
        } catch (Exception $e) {
            $reason = '';
            if (str_contains($e->getMessage(), 'Integrity constraint violation')) {
                $reason = $this->t('Other datasets refer to this one.');
            }
            $msg = sprintf($this->t('Dataset could not be removed. %s'), $reason);

            return new Error($msg, 422);
        }

        return new Response(json_encode(['success' => true]));
    }

    #[Route(path: '/team/save')]
    public function saveTeamAction(): Response
    {
        if (!$this->checkLogin()) {
            return $this->getFailedLoginResponse();
        }

        if (false === $this->isPl()) {
            return $this->getFailedAuthorizationResponse();
        }

        $repository = $this->doctrine->getRepository('App:Team');

        $id       = (int) $this->request->get('id');
        $name     = $this->request->get('name');
        $teamLead = $this->request->get('lead_user_id') ?
            $this->doctrine
                ->getRepository('App:User')
                ->find($this->request->get('lead_user_id'))
            : null;

        if ($id) {
            $team = $repository->find($id);
        } else {
            $team = new Team();
        }

        if ($sameNamedTeam = $repository->findOneByName($name)) {
            if ($team->getId() !== $sameNamedTeam->getId()) {
                $response = new Response($this->t('The team name provided already exists.'));
                $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);

                return $response;
            }
        }

        if (null === $teamLead) {
            $response = new Response($this->t('Please provide a valid user as team leader.'));
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);

            return $response;
        }

        try {
            $team
                ->setName($name)
                ->setLeadUser($teamLead)
            ;

            $em = $this->doctrine->getManager();
            $em->persist($team);
            $em->flush();
        } catch (Exception $e) {
            $response = new Response($this->t('Error on save').': '.$e->getMessage());
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_FORBIDDEN);

            return $response;
        }

        $data = [$team->getId(), $team->getName(), ($team->getLeadUser() ? $team->getLeadUser()->getId() : '')];

        return new Response(json_encode($data, \JSON_THROW_ON_ERROR));
    }

    #[Route(path: '/team/delete')]
    public function deleteTeamAction(): Error|Response
    {
        if (false === $this->isPl()) {
            return $this->getFailedAuthorizationResponse();
        }

        try {
            $id       = (int) $this->request->get('id');
            $doctrine = $this->doctrine;

            $team = $doctrine->getRepository('App:Team')
                ->find($id)
            ;

            $em = $doctrine->getManager();
            $em->remove($team);
            $em->flush();
        } catch (Exception $e) {
            $reason = '';
            if (str_contains($e->getMessage(), 'Integrity constraint violation')) {
                $reason = $this->t('Other datasets refer to this one.');
            }
            $msg = sprintf($this->t('Dataset could not be removed. %s'), $reason);

            return new Error($msg, 422);
        }

        return new Response(json_encode(['success' => true]));
    }

    #[Route(path: '/syncentries/jira')]
    public function jiraSyncEntriesAction(): Response
    {
        if (!$this->checkLogin()) {
            return $this->getFailedLoginResponse();
        }

        if (false === $this->isPl()) {
            return $this->getFailedAuthorizationResponse();
        }

        $doctrine = $this->doctrine;

        $users = $doctrine
            ->getRepository('App:User')
            ->findAll()
        ;

        $ticketSystems = $doctrine
            ->getRepository('App:TicketSystem')
            ->findAll()
        ;

        $data = [];

        /** @var User $user */
        foreach ($users as $user) {
            /** @var TicketSystem $ticketSystem */
            foreach ($ticketSystems as $ticketSystem) {
                try {
                    $jiraOauthApi = new JiraOAuthApi($user, $ticketSystem, $doctrine, $this->container->get('router'));
                    $jiraOauthApi->updateAllEntriesJiraWorkLogs();
                    $data[$ticketSystem->getName().' | '.$user->getUsername()] = 'success';
                } catch (Exception $e) {
                    $data[$ticketSystem->getName().' | '.$user->getUsername()] = 'error ('.$e->getMessage().')';
                }
            }
        }

        return new Response(json_encode($data, \JSON_THROW_ON_ERROR));
    }

    #[Route(path: '/getContracts', name: '_getContracts')]
    public function getContractsAction(): Response
    {
        if (!$this->checkLogin()) {
            return $this->getFailedLoginResponse();
        }

        /** @var \App\Repository\ContractRepository $repo */
        $repo = $this->doctrine->getRepository('App:Contract');

        return new Response(json_encode($repo->getContracts(), \JSON_THROW_ON_ERROR));
    }

    /**
     * @throws Exception
     */
    #[Route(path: '/contract/save')]
    public function saveContractAction(): Response
    {
        if (false === $this->isPl()) {
            return $this->getFailedAuthorizationResponse();
        }

        $data       = null;
        $contractId = (int) $this->request->get('id');
        $start      = $this->request->get('start');
        $end        = $this->request->get('end');
        $hours_0    = $this->request->get('hours_0');
        $hours_1    = $this->request->get('hours_1');
        $hours_2    = $this->request->get('hours_2');
        $hours_3    = $this->request->get('hours_3');
        $hours_4    = $this->request->get('hours_4');
        $hours_5    = $this->request->get('hours_5');
        $hours_6    = $this->request->get('hours_6');
        /** @var User $user */
        $user = $this->request->get('user_id') ?
            $this->doctrine
                ->getRepository('App:User')
                ->find($this->request->get('user_id'))
            : null;

        /** @var \App\Repository\ContractRepository $contractRepository */
        $contractRepository = $this->doctrine->getRepository('App:Contract');

        if ($contractId) {
            $contract = $contractRepository->find($contractId);
        } else {
            $contract = new Contract();
        }

        if (!$user) {
            $response = new Response($this->t('Please enter a valid user.'));
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);

            return $response;
        }

        $dateStart = DateTime::createFromFormat('Y-m-d', $start);
        if (!$dateStart) {
            $response = new Response($this->t('Please enter a valid contract start.'));
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);

            return $response;
        }
        $dateStart->setDate($dateStart->format('Y'), $dateStart->format('m'), 1);
        $dateStart->setTime(0, 0, 0);

        $dateEnd = DateTime::createFromFormat('Y-m-d', $end);
        if ($dateEnd) {
            $dateEnd->setDate($dateEnd->format('Y'), $dateEnd->format('m'), 1);
            $dateEnd->add(new DateInterval('P1M'));
            $dateEnd->sub(new DateInterval('P1D'));
            $dateEnd->setTime(23, 59, 59);

            if ($dateEnd < $dateStart) {
                $response = new Response($this->t('End date has to be greater than the start date.'));
                $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);

                return $response;
            }
        } else {
            $dateEnd = null;
        }

        $contract->setUser($user)
            ->setStart($dateStart)
            ->setEnd($dateEnd)
            ->setHours0($hours_0)
            ->setHours1($hours_1)
            ->setHours2($hours_2)
            ->setHours3($hours_3)
            ->setHours4($hours_4)
            ->setHours5($hours_5)
            ->setHours6($hours_6)
        ;

        $em = $this->doctrine->getManager();
        $em->persist($contract);
        $em->flush();

        $data = [$contract->getId()];

        return new Response(json_encode($data, \JSON_THROW_ON_ERROR));
    }

    #[Route(path: '/contract/delete')]
    public function deleteContractAction(): Error|Response
    {
        if (false === $this->isPl()) {
            return $this->getFailedAuthorizationResponse();
        }

        try {
            $id       = (int) $this->request->get('id');
            $doctrine = $this->doctrine;

            $contract = $doctrine->getRepository('App:Contract')
                ->find($id)
            ;

            $em = $doctrine->getManager();
            $em->remove($contract);
            $em->flush();
        } catch (Exception $e) {
            $reason = '';
            if (str_contains($e->getMessage(), 'Integrity constraint violation')) {
                $reason = $this->t('Other datasets refer to this one.');
            }
            $msg = sprintf($this->t('Dataset could not be removed. %s'), $reason);

            return new Error($msg, 422);
        }

        return new Response(json_encode(['success' => true]));
    }
}
