<?php declare(strict_types=1);

namespace App\Controller;

use ReflectionException;
use Exception;
use DateTime;
use DateInterval;
use App\Model\Response;
use App\Entity\Contract;
use App\Entity\Team;
use App\Response\Error;
use App\Entity\Project;
use App\Entity\Customer;
use App\Entity\User;
use App\Entity\Preset;
use App\Entity\TicketSystem;
use App\Entity\Activity;
use App\Helper\TimeHelper;
use App\Services\JiraOAuthApi;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Class AdminController.
 */
class AdminController extends BaseController
{
    public function getAllProjectsAction(): Response
    {
        $result = $this->projectRepo->findAll();

        $data = [];
        foreach ($result as $project) {
            $data[] = ['project' => $project->toArray()];
        }

        return new Response(json_encode($data, \JSON_THROW_ON_ERROR));
    }

    #[Route(path: '/getAllCustomers', name: '_getAllCustomers')]
    public function getCustomersAction(): Response
    {
        return new Response(json_encode($this->customerRepo->getAllCustomers(), \JSON_THROW_ON_ERROR));
    }

    #[Route(path: '/getAllUsers', name: '_getAllUsers')]
    public function getUsersAction(): Response
    {
        return new Response(json_encode($this->userRepo->getAllUsers(), \JSON_THROW_ON_ERROR));
    }

    #[Route(path: '/getAllTeams', name: '_getAllTeams')]
    public function getTeamsAction(): Response
    {
        return new Response(json_encode($this->teamRepo->findAll(), \JSON_THROW_ON_ERROR));
    }

    #[Route(path: '/getAllPresets', name: '_getAllPresets')]
    public function getPresetsAction(): Response
    {
        return new Response(json_encode($this->presetRepo->getAllPresets(), \JSON_THROW_ON_ERROR));
    }

    /**
     * @throws ReflectionException
     */
    #[Route(path: '/getTicketSystems', name: '_getTicketSystems')]
    public function getTicketSystemsAction(): Response
    {
        $ticketSystems = $this->ticketSystemRepo->getAllTicketSystems();

        if (false === $this->isGranted('ROLE_PL')) {
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
        $this->denyAccessUnlessGranted('ROLE_PL');

        $data      = null;
        $projectId = (int) $this->request->get('id');
        $name      = $this->request->get('name');

        $ticketSystem  = $this->ticketSystemRepo->find($this->request->get('ticket_system'));
        $projectLead   = $this->userRepo->find($this->request->get('project_lead'));
        $technicalLead = $this->userRepo->find($this->request->get('technical_lead'));

        $jiraId                            = strtoupper($this->request->get('jiraId'));
        $active                            = $this->request->request->getBoolean('active', true);
        $global                            = $this->request->request->getBoolean('global', false);
        $estimation                        = TimeHelper::readable2minutes($this->request->get('estimation') ?: '0m');
        $billing                           = $this->request->get('billing') ?: 0;
        $costCenter                        = $this->request->get('cost_center') ?: null;
        $offer                             = $this->request->get('offer') ?: 0;
        $additionalInformationFromExternal = $this->request->request->getBoolean('additionalInformationFromExternal');
        $internalJiraTicketSystem          = $this->request->request->getInt('internalJiraTicketSystem', 0);
        $internalJiraProjectKey            = $this->request->get('internalJiraProjectKey', 0);

        if ($projectId) {
            $project = $this->projectRepo->find($projectId);
        } else {
            $project = new Project();

            /** @var Customer $customer */
            $customer = $this->customerRepo->find($this->request->get('customer'));

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

        $sameNamedProject = $this->projectRepo->findOneBy(
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

        if (\strlen($jiraId) && false === $this->projectRepo->isValidJiraPrefix($jiraId)) {
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

        $this->em->persist($project);
        $this->em->flush();

        $data = [$project->getId(), $name, $project->getCustomer()->getId(), $jiraId];

        return new Response(json_encode($data, \JSON_THROW_ON_ERROR));
    }

    #[Route(path: '/project/delete')]
    public function deleteProjectAction(): Error|Response
    {
        $this->denyAccessUnlessGranted('ROLE_PL');

        try {
            $id      = (int) $this->request->get('id');
            $project = $this->projectRepo->find($id);

            $this->em->remove($project);
            $this->em->flush();
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
        $this->denyAccessUnlessGranted('ROLE_PL');

        $data       = null;
        $customerId = (int) $this->request->get('id');
        $name       = $this->request->get('name');
        $active     = (bool) $this->request->get('active');
        $global     = (bool) $this->request->get('global');
        $teamIds    = $this->request->get('teams') ?: [];

        if ($customerId) {
            $customer = $this->customerRepo->find($customerId);
        } else {
            $customer = new Customer();
        }

        if (\strlen($name) < 3) {
            $response = new Response($this->t('Please provide a valid customer name with at least 3 letters.'));
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);

            return $response;
        }

        if ($sameNamedCustomer = $this->customerRepo->findOneByName($name)) {
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
            if ($team = $this->teamRepo->find((int) $teamId)) {
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

        $this->em->persist($customer);
        $this->em->flush();

        $data = [$customer->getId(), $name, $active, $global, $teamIds];

        return new Response(json_encode($data, \JSON_THROW_ON_ERROR));
    }

    #[Route(path: '/customer/delete')]
    public function deleteCustomerAction(): Error|Response
    {
        $this->denyAccessUnlessGranted('ROLE_PL');

        try {
            $id       = (int) $this->request->get('id');
            $customer = $this->customerRepo->find($id);

            $this->em->remove($customer);
            $this->em->flush();
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
        $this->denyAccessUnlessGranted('ROLE_PL');

        $userId  = (int) $this->request->get('id');
        $name    = $this->request->get('username');
        $abbr    = $this->request->get('abbr');
        $type    = $this->request->get('type');
        $locale  = $this->request->get('locale');
        $teamIds = $this->request->get('teams') ?: [];

        if ($userId) {
            /** @var User $user */
            $user = $this->userRepo->find($userId);
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

        if ($sameNamedUser = $this->userRepo->findOneByUsername($name)) {
            if ($user->getId() !== $sameNamedUser->getId()) {
                $response = new Response($this->t('The user name provided already exists.'));
                $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);

                return $response;
            }
        }

        if ($sameAbbrUser = $this->userRepo->findOneByAbbr($abbr)) {
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
            ->setShowFuture(true)
        ;

        $user->resetTeams();

        foreach ($teamIds as $teamId) {
            if (!$teamId) {
                continue;
            }
            if ($team = $this->teamRepo->find((int) $teamId)) {
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

        $this->em->persist($user);
        $this->em->flush();

        $data = [$user->getId(), $name, $abbr, $type];

        return new Response(json_encode($data, \JSON_THROW_ON_ERROR));
    }

    #[Route(path: '/user/delete')]
    public function deleteUserAction(): Error|Response
    {
        $this->denyAccessUnlessGranted('ROLE_PL');

        try {
            $id   = (int) $this->request->get('id');
            $user = $this->userRepo->find($id);

            $this->em->remove($user);
            $this->em->flush();
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
        $this->denyAccessUnlessGranted('ROLE_PL');

        try {
            $id     = (int) $this->request->get('id');
            $preset = $this->presetRepo->find($id);

            $this->em->remove($preset);
            $this->em->flush();
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
        $this->denyAccessUnlessGranted('ROLE_PL');

        $id          = (int) $this->request->get('id');
        $name        = $this->request->get('name');
        $customer    = $this->customerRepo->find($this->request->get('customer'));
        $project     = $this->projectRepo->find($this->request->get('project'));
        $activity    = $this->activityRepo->find($this->request->get('activity'));
        $description = $this->request->get('description');

        if (\strlen($name) < 3) {
            $response = new Response($this->t('Please provide a valid preset name with at least 3 letters.'));
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);

            return $response;
        }

        if ($id) {
            $preset = $this->presetRepo->find($id);
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

            $this->em->persist($preset);
            $this->em->flush();
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
        $this->denyAccessUnlessGranted('ROLE_PL');

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
            $ticketSystem = $this->ticketSystemRepo->find($id);
        } else {
            $ticketSystem = new TicketSystem();
        }

        if (\strlen($name) < 3) {
            $response = new Response($this->t('Please provide a valid ticket system name with at least 3 letters.'));
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);

            return $response;
        }

        if ($sameNamedSystem = $this->ticketSystemRepo->findOneByName($name)) {
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

            $this->em->persist($ticketSystem);
            $this->em->flush();
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
        $this->denyAccessUnlessGranted('ROLE_PL');

        try {
            $id           = (int) $this->request->get('id');
            $ticketSystem = $this->ticketSystemRepo->find($id);

            $this->em->remove($ticketSystem);
            $this->em->flush();
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
        $this->denyAccessUnlessGranted('ROLE_PL');

        $id          = (int) $this->request->get('id');
        $name        = $this->request->get('name');
        $needsTicket = (bool) $this->request->get('needsTicket');
        $factor      = str_replace(',', '.', $this->request->get('factor'));

        if ($id) {
            $activity = $this->activityRepo->find($id);
        } else {
            $activity = new Activity();
        }

        if ($sameNamedActivity = $this->activityRepo->findOneByName($name)) {
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

            $this->em->persist($activity);
            $this->em->flush();
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
        $this->denyAccessUnlessGranted('ROLE_PL');

        try {
            $id = (int) $this->request->get('id');
            $this->em->remove($this->activityRepo->find($id));
            $this->em->flush();
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
        $this->denyAccessUnlessGranted('ROLE_PL');

        $id       = (int) $this->request->get('id');
        $name     = $this->request->get('name');
        $teamLead = $this->request->get('lead_user_id') ?
            $this->userRepo->find($this->request->get('lead_user_id'))
            : null;

        if ($id) {
            /** @var Team $team */
            $team = $this->teamRepo->find($id);
        } else {
            $team = new Team();
        }

        if ($sameNamedTeam = $this->teamRepo->findOneByName($name)) {
            if ($team->getId() !== $sameNamedTeam->getId()) {
                $response = new Response($this->t('The team name provided already exists.'));
                $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);

                return $response;
            }
        }

        // Disabled enforcement of team lead, because of:
        // You cannot create a user, because it requires a team,
        // and you cannot create a team, because it requires a user.
        // if (null === $teamLead) {
        //     $response = new Response($this->t('Please provide a valid user as team leader.'));
        //     $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);

        //     return $response;
        // }

        try {
            $team
                ->setName($name)
                ->setLeadUser($teamLead)
            ;

            $this->em->persist($team);
            $this->em->flush();
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
        $this->denyAccessUnlessGranted('ROLE_PL');

        try {
            $id   = (int) $this->request->get('id');
            $team = $this->teamRepo->find($id);

            $this->em->remove($team);
            $this->em->flush();
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

    protected function getJiraOAuthApi(User $user, TicketSystem $ticketSystem): JiraOAuthApi
    {
        return $this->container->get('JiraOAuthApi')
            ->setUser($user)
            ->setTicketSystem($ticketSystem);
    }

    #[Route(path: '/syncentries/jira')]
    public function jiraSyncEntriesAction(): Response
    {
        $this->denyAccessUnlessGranted('ROLE_PL');

        $users         = $this->userRepo->findAll();
        $ticketSystems = $this->ticketSystemRepo->findAll();
        $data          = [];

        /** @var User $user */
        foreach ($users as $user) {
            /** @var TicketSystem $ticketSystem */
            foreach ($ticketSystems as $ticketSystem) {
                try {
                    $this->getJiraOAuthApi($user, $ticketSystem)->updateAllEntriesJiraWorkLogs();
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
        return new Response(json_encode($this->contractRepo->getContracts(), \JSON_THROW_ON_ERROR));
    }

    /**
     * @throws Exception
     */
    #[Route(path: '/contract/save')]
    public function saveContractAction(): Response
    {
        $this->denyAccessUnlessGranted('ROLE_PL');

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
            $this->userRepo->find($this->request->get('user_id'))
            : null;

        if ($contractId) {
            $contract = $this->contractRepo->find($contractId);
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
        $dateStart->setDate((int) $dateStart->format('Y'), (int) $dateStart->format('m'), 1);
        $dateStart->setTime(0, 0, 0);

        $dateEnd = DateTime::createFromFormat('Y-m-d', $end);
        if ($dateEnd) {
            $dateEnd->setDate((int) $dateEnd->format('Y'), (int) $dateEnd->format('m'), 1);
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

        $this->em->persist($contract);
        $this->em->flush();

        $data = [$contract->getId()];

        return new Response(json_encode($data, \JSON_THROW_ON_ERROR));
    }

    #[Route(path: '/contract/delete')]
    public function deleteContractAction(): Error|Response
    {
        $this->denyAccessUnlessGranted('ROLE_PL');

        try {
            $id       = (int) $this->request->get('id');
            $contract = $this->contractRepo->find($id);

            $this->em->remove($contract);
            $this->em->flush();
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
