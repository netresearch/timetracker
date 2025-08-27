<?php
declare(strict_types=1);

namespace App\Controller;

use App\Entity\Activity;
use App\Entity\Contract;
use App\Entity\Customer;
use App\Entity\Preset;
use App\Entity\Project;
use App\Entity\Team;
use App\Entity\TicketSystem;
use App\Entity\User;
use App\Service\Util\TimeCalculationService;
use App\Model\JsonResponse;
use App\Model\Response;
use App\Repository\UserRepository;
use App\Response\Error;
use App\Service\Integration\Jira\JiraOAuthApiFactory;
use App\Service\SubticketSyncService;
use App\Util\RequestEntityHelper;
use App\Util\RequestHelper;
use Symfony\Component\HttpFoundation\Request;

/**
 * Class AdminController.
 */
class AdminController extends BaseController
{
    private SubticketSyncService $subticketSyncService;

    private JiraOAuthApiFactory $jiraOAuthApiFactory;

    private TimeCalculationService $timeCalculationService;

    #[\Symfony\Contracts\Service\Attribute\Required]
    public function setSubticketSyncService(SubticketSyncService $subticketSyncService): void
    {
        $this->subticketSyncService = $subticketSyncService;
    }

    #[\Symfony\Contracts\Service\Attribute\Required]
    public function setJiraApiFactory(JiraOAuthApiFactory $jiraOAuthApiFactory): void
    {
        $this->jiraOAuthApiFactory = $jiraOAuthApiFactory;
    }

    #[\Symfony\Contracts\Service\Attribute\Required]
    public function setTimeCalculationService(TimeCalculationService $timeCalculationService): void
    {
        $this->timeCalculationService = $timeCalculationService;
    }

    #[\Deprecated]
    public function getCustomers(Request $request): Response|JsonResponse
    {
        if (!$this->checkLogin($request)) {
            return $this->getFailedLoginResponse();
        }

        /** @var \App\Repository\CustomerRepository $objectRepository */
        $objectRepository = $this->doctrineRegistry->getRepository(Customer::class);

        return new JsonResponse($objectRepository->getAllCustomers());
    }

    #[\Deprecated]
    public function getUsers(Request $request): Response|JsonResponse
    {
        if (!$this->checkLogin($request)) {
            // For non-JSON clients, redirect to login, otherwise 401 JSON
        	$redirect = $this->login($request);
            if ($redirect instanceof \Symfony\Component\HttpFoundation\RedirectResponse) {
                // Wrap into App\Model\Response to satisfy return type while preserving 302
                $response = new Response('');
                $response->setStatusCode(302);
                $response->headers->set('Location', $redirect->getTargetUrl());

                return $response;
            }

            // Fallback: 401 JSON/text
            return $this->getFailedLoginResponse();
        }

        /** @var UserRepository $objectRepository */
        $objectRepository = $this->doctrineRegistry->getRepository(User::class);

        return new JsonResponse($objectRepository->getAllUsers());
    }

    #[\Deprecated]
    public function getTeams(Request $request): Response|JsonResponse
    {
        if (!$this->checkLogin($request)) {
            return $this->getFailedLoginResponse();
        }

        /** @var \App\Repository\TeamRepository $objectRepository */
        $objectRepository = $this->doctrineRegistry->getRepository(Team::class);

        return new JsonResponse($objectRepository->getAllTeamsAsArray());
    }

    #[\Deprecated]
    public function getPresets(Request $request): Response|JsonResponse
    {
        if (!$this->checkLogin($request)) {
            return $this->getFailedLoginResponse();
        }

        /** @var \App\Repository\PresetRepository $objectRepository */
        $objectRepository = $this->doctrineRegistry->getRepository(Preset::class);

        return new JsonResponse($objectRepository->getAllPresets());
    }

    #[\Deprecated]
    public function deleteProject(Request $request): Response|Error|JsonResponse
    {
        if (false === $this->isPl($request)) {
            return $this->getFailedAuthorizationResponse();
        }

        try {
            $id = (int) $request->request->get('id');
            $doctrine = $this->doctrineRegistry;

            $project = $doctrine->getRepository(Project::class)
                ->find($id);

            $em = $doctrine->getManager();
            if ($project) {
                $em->remove($project);
                $em->flush();
            } else {
                throw new \RuntimeException('Already deleted');
            }
        } catch (\Exception $exception) {
            $reason = '';
            if (str_contains($exception->getMessage(), 'Integrity constraint violation')) {
                $reason = $this->translate('Other datasets refer to this one.');
            }

            $msg = sprintf($this->translate('Dataset could not be removed. %s'), $reason);

            return new Error($msg, \Symfony\Component\HttpFoundation\Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(['success' => true]);
    }

    #[\Deprecated]
    public function deleteCustomer(Request $request): Response|Error|JsonResponse
    {
        if (false === $this->isPl($request)) {
            return $this->getFailedAuthorizationResponse();
        }

        try {
            $id = (int) $request->request->get('id');
            $doctrine = $this->doctrineRegistry;

            $customer = $doctrine->getRepository(Customer::class)
                ->find($id);

            $em = $doctrine->getManager();
            if ($customer) {
                $em->remove($customer);
                $em->flush();
            } else {
                throw new \RuntimeException('Already deleted');
            }
        } catch (\Exception $exception) {
            $reason = '';
            if (str_contains($exception->getMessage(), 'Integrity constraint violation')) {
                $reason = $this->translate('Other datasets refer to this one.');
            }

            $msg = sprintf($this->translate('Dataset could not be removed. %s'), $reason);

            return new Error($msg, \Symfony\Component\HttpFoundation\Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(['success' => true]);
    }

    #[\Deprecated]
    public function saveUser(Request $request): Response|Error|JsonResponse
    {
        if (false === $this->isPl($request)) {
            return $this->getFailedAuthorizationResponse();
        }

        $userId = (int) $request->request->get('id');
        $name = RequestHelper::string($request, 'username');
        $abbr = RequestHelper::string($request, 'abbr');
        $type = RequestHelper::string($request, 'type');
        $locale = RequestHelper::string($request, 'locale');
        $teamIds = $request->request->all('teams') ?: [];

        /** @var UserRepository $objectRepository */
        $objectRepository = $this->doctrineRegistry->getRepository(User::class);

        $user = 0 !== $userId ? $objectRepository->find($userId) : new User();
        if (!$user instanceof User) {
            return new Error($this->translate('No entry for id.'), \Symfony\Component\HttpFoundation\Response::HTTP_NOT_FOUND);
        }

        if (strlen($name) < 3) {
            $response = new Response($this->translate('Please provide a valid user name with at least 3 letters.'));
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);

            return $response;
        }

        if (3 != strlen($abbr)) {
            $response = new Response($this->translate('Please provide a valid user name abbreviation with 3 letters.'));
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);

            return $response;
        }

        if (($sameNamedUser = $objectRepository->findOneByUsername($name)) instanceof User && $user->getId() !== $sameNamedUser->getId()) {
            $response = new Response($this->translate('The user name provided already exists.'));
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);

            return $response;
        }

        if (($sameAbbrUser = $objectRepository->findOneByAbbr($abbr)) instanceof User && $user->getId() !== $sameAbbrUser->getId()) {
            $response = new Response($this->translate('The user name abreviation provided already exists.'));
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);

            return $response;
        }

        // $user is ensured to be a User above

        $user->setUsername($name)
            ->setAbbr($abbr)
            ->setLocale($locale)
            ->setType($type);

        $user->resetTeams();

        foreach ($teamIds as $teamId) {
            if (!$teamId) {
                continue;
            }

            $team = $this->doctrineRegistry->getRepository(Team::class)->find((int) $teamId);
            if ($team instanceof Team) {
                $user->addTeam($team);
            } else {
                $response = new Response(sprintf($this->translate('Could not find team with ID %s.'), (int) $teamId));
                $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);

                return $response;
            }
        }

        if (0 == $user->getTeams()->count()) {
            $response = new Response($this->translate('Every user must belong to at least one team'));
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);

            return $response;
        }

        $objectManager = $this->doctrineRegistry->getManager();
        $objectManager->persist($user);
        $objectManager->flush();

        $data = [$user->getId(), $name, $abbr, $type];

        return new JsonResponse($data);
    }

    #[\Deprecated]
    public function deleteUser(Request $request): Response|Error|JsonResponse
    {
        if (false === $this->isPl($request)) {
            return $this->getFailedAuthorizationResponse();
        }

        try {
            $id = (int) $request->request->get('id');
            $doctrine = $this->doctrineRegistry;

            $user = $doctrine->getRepository(User::class)
                ->find($id);

            $em = $this->doctrineRegistry->getManager();
            if ($user) {
                $em->remove($user);
                $em->flush();
            } else {
                throw new \RuntimeException('Already deleted');
            }
        } catch (\Exception $exception) {
            $reason = '';
            if (str_contains($exception->getMessage(), 'Integrity constraint violation')) {
                $reason = $this->translate('Other datasets refer to this one.');
            }

            $msg = sprintf($this->translate('Dataset could not be removed. %s'), $reason);

            return new Error($msg, \Symfony\Component\HttpFoundation\Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(['success' => true]);
    }

    #[\Deprecated]
    public function deletePreset(Request $request): Response|Error|JsonResponse
    {
        if (false === $this->isPl($request)) {
            return $this->getFailedAuthorizationResponse();
        }

        try {
            $id = (int) $request->request->get('id');
            $doctrine = $this->doctrineRegistry;

            $preset = $doctrine->getRepository(Preset::class)
                    ->find($id);

            $em = $this->doctrineRegistry->getManager();
            if ($preset) {
                $em->remove($preset);
                $em->flush();
            } else {
                throw new \RuntimeException('Already deleted');
            }
        } catch (\Exception $exception) {
            $reason = '';
            if (str_contains($exception->getMessage(), 'Integrity constraint violation')) {
                $reason = $this->translate('Other datasets refer to this one.');
            }

            $msg = sprintf($this->translate('Dataset could not be removed. %s'), $reason);

            return new Error($msg, \Symfony\Component\HttpFoundation\Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(['success' => true]);
    }

    #[\Deprecated]
    public function deleteTicketSystem(Request $request): Response|Error|JsonResponse
    {
        if (false === $this->isPl($request)) {
            return $this->getFailedAuthorizationResponse();
        }

        try {
            $id = (int) $request->request->get('id');
            $doctrine = $this->doctrineRegistry;

            $ticketSystem = $doctrine->getRepository(TicketSystem::class)
                ->find($id);

            $em = $this->doctrineRegistry->getManager();
            if ($ticketSystem) {
                $em->remove($ticketSystem);
                $em->flush();
            } else {
                throw new \RuntimeException('Already deleted');
            }
        } catch (\Exception $exception) {
            $reason = '';
            if (str_contains($exception->getMessage(), 'Integrity constraint violation')) {
                $reason = $this->translate('Other datasets refer to this one.');
            }

            $msg = sprintf($this->translate('Dataset could not be removed. %s'), $reason);

            return new Error($msg, \Symfony\Component\HttpFoundation\Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(['success' => true]);
    }

    #[\Deprecated]
    public function saveTeam(Request $request): Response|Error|JsonResponse
    {
        if (!$this->checkLogin($request)) {
            return $this->getFailedLoginResponse();
        }

        if (false === $this->isPl($request)) {
            return $this->getFailedAuthorizationResponse();
        }

        /** @var \App\Repository\TeamRepository $objectRepository */
        $objectRepository = $this->doctrineRegistry->getRepository(Team::class);

        $id = (int) $request->request->get('id');
        $name = (string) ($request->request->get('name') ?? '');
        $teamLead = $request->request->get('lead_user_id') ?
            $this->doctrineRegistry->getRepository(User::class)
                ->find($request->request->get('lead_user_id'))
            : null;

        if (0 !== $id) {
            $team = $objectRepository->find($id);
            // abort for non existing id
            if (!$team) {
                $message = $this->translator->trans('No entry for id.');

                return new Error($message, \Symfony\Component\HttpFoundation\Response::HTTP_NOT_FOUND);
            }

            if (!$team instanceof Team) {
                return new Error($this->translate('No entry for id.'), \Symfony\Component\HttpFoundation\Response::HTTP_NOT_FOUND);
            }
        } else {
            $team = new Team();
        }

        $sameNamedTeam = $objectRepository->findOneByName($name);
        if ($sameNamedTeam instanceof Team && $team->getId() !== $sameNamedTeam->getId()) {
            $response = new Response($this->translate('The team name provided already exists.'));
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);

            return $response;
        }

        if (!$teamLead instanceof User) {
            $response = new Response($this->translate('Please provide a valid user as team leader.'));
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);

            return $response;
        }

        try {
            $team
                ->setName($name)
                ->setLeadUser($teamLead);

            $em = $this->doctrineRegistry->getManager();
            $em->persist($team);
            $em->flush();
        } catch (\Exception $exception) {
            $response = new Response($this->translate('Error on save').': '.$exception->getMessage());
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_FORBIDDEN);

            return $response;
        }

        $data = [$team->getId(), $team->getName(), $team->getLeadUser() instanceof \App\Entity\User ? $team->getLeadUser()->getId() : ''];

        return new JsonResponse($data);
    }

    #[\Deprecated]
    public function deleteTeam(Request $request): Response|Error|JsonResponse
    {
        if (false === $this->isPl($request)) {
            return $this->getFailedAuthorizationResponse();
        }

        try {
            $id = (int) $request->request->get('id');
            $doctrine = $this->doctrineRegistry;

            $team = $doctrine->getRepository(Team::class)
                ->find($id);

            $em = $this->doctrineRegistry->getManager();
            if ($team) {
                $em->remove($team);
                $em->flush();
            } else {
                throw new \RuntimeException('Already deleted');
            }
        } catch (\Exception $exception) {
            $reason = '';
            if (str_contains($exception->getMessage(), 'Integrity constraint violation')) {
                $reason = $this->translate('Other datasets refer to this one.');
            }

            $msg = sprintf($this->translate('Dataset could not be removed. %s'), $reason);

            return new Error($msg, \Symfony\Component\HttpFoundation\Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(['success' => true]);
    }

    #[\Deprecated]
    public function saveContract(Request $request): Response|Error|JsonResponse
    {
        if (false === $this->isPl($request)) {
            return $this->getFailedAuthorizationResponse();
        }

        $contractId = (int) $request->request->get('id');
        $start = (string) ($request->request->get('start') ?? '');
        $end = (string) ($request->request->get('end') ?? '');
        $hours_0 = (float) str_replace(',', '.', (string) ($request->request->get('hours_0') ?? '0'));
        $hours_1 = (float) str_replace(',', '.', (string) ($request->request->get('hours_1') ?? '0'));
        $hours_2 = (float) str_replace(',', '.', (string) ($request->request->get('hours_2') ?? '0'));
        $hours_3 = (float) str_replace(',', '.', (string) ($request->request->get('hours_3') ?? '0'));
        $hours_4 = (float) str_replace(',', '.', (string) ($request->request->get('hours_4') ?? '0'));
        $hours_5 = (float) str_replace(',', '.', (string) ($request->request->get('hours_5') ?? '0'));
        $hours_6 = (float) str_replace(',', '.', (string) ($request->request->get('hours_6') ?? '0'));
        /** @var User|object|null $user */
        $user = $request->request->get('user_id') ?
            $this->doctrineRegistry->getRepository(User::class)
                ->find($request->request->get('user_id'))
            : null;

        /** @var \App\Repository\ContractRepository $objectRepository */
        $objectRepository = $this->doctrineRegistry->getRepository(Contract::class);

        if (0 !== $contractId) {
            $contract = $objectRepository->find($contractId);
            if (!$contract) {
                $message = $this->translator->trans('No entry for id.');

                return new Error($message, \Symfony\Component\HttpFoundation\Response::HTTP_NOT_FOUND);
            }

            if (!$contract instanceof Contract) {
                return new Error($this->translate('No entry for id.'), \Symfony\Component\HttpFoundation\Response::HTTP_NOT_FOUND);
            }
        } else {
            $contract = new Contract();
        }

        if (!$user instanceof User) {
            $response = new Response($this->translate('Please enter a valid user.'));
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);

            return $response;
        }

        $dateStart = \DateTime::createFromFormat('Y-m-d', $start);
        if (!$dateStart) {
            $response = new Response($this->translate('Please enter a valid contract start.'));
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);

            return $response;
        }

        $dateStart->setDate((int) $dateStart->format('Y'), (int) $dateStart->format('m'), (int) $dateStart->format('d'));
        $dateStart->setTime(0, 0, 0);

        $dateEnd = \DateTime::createFromFormat('Y-m-d', $end);
        if ($dateEnd) {
            $dateEnd->setDate((int) $dateEnd->format('Y'), (int) $dateEnd->format('m'), (int) $dateEnd->format('d'));
            $dateEnd->setTime(23, 59, 59);

            if ($dateEnd < $dateStart) {
                $response = new Response($this->translate('End date has to be greater than the start date.'));
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
            ->setHours6($hours_6);

        $objectManager = $this->doctrineRegistry->getManager();
        $objectManager->persist($contract);

        // when updating a existing contract don't look for other contracts for the user
        if (0 !== $contractId) {
            $objectManager->flush();

            return new JsonResponse([$contract->getId()]);
        }

        // update old contracts,
        $responseMessage = $this->updateOldContract($user, $dateStart, $dateEnd);
        if ('' !== $responseMessage && '0' !== $responseMessage) {
            $response = new Response($responseMessage);
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);

            return $response;
        }

        $objectManager->flush();

        return new JsonResponse([$contract->getId()]);
    }

    /**
     * Look for existing contracts for user and update the latest if open-ended
     * When updating to PHP8 change return type to string|null.
     */
    protected function updateOldContract(User $user, \DateTime $newStartDate, ?\DateTime $newEndDate): string
    {
        $objectManager = $this->doctrineRegistry->getManager();
        $objectRepository = $this->doctrineRegistry->getRepository(Contract::class);

        // get existing contracts for the user
        /** @var array<int, Contract> $contractsOld */
        $contractsOld = $objectRepository->findBy(['user' => $user]);

        if (!$contractsOld) {
            return '';
        }

        if ($this->checkOldContractsStartDateOverlap($contractsOld, $newStartDate, $newEndDate)) {
            return $this->translate('There is already an ongoing contract with a start date in the future that overlaps with the new contract.');
        }

        if ($this->checkOldContractsEndDateOverlap($contractsOld, $newStartDate)) {
            return $this->translate('There is already an ongoing contract with a closed end date in the future.');
        }

        // filter to get only open-ended contracts
        $contractsOld = array_filter($contractsOld, fn (\App\Entity\Contract $contract): bool => (null === $contract->getEnd()));
        if (count($contractsOld) > 1) {
            return $this->translate('There is more than one open-ended contract for the user.');
        }

        if ([] === $contractsOld) {
            return '';
        }

        $contractOld = array_values($contractsOld)[0];

        // alter exisiting contract with open end
        // |--old--(update)
        //      |--new----(|)->
        if ($contractOld->getStart() <= $newStartDate) {
            $oldContractEndDate = clone $newStartDate;
            $contractOld->setEnd($oldContractEndDate->sub(new \DateInterval('P1D')));
            $objectManager->persist($contractOld);
            $objectManager->flush();
        }

        // skip old contract edit for
        // |--new--| |--old--(|)-->
        // and
        // |--old--| |--new--(|)-->
        return '';
    }

    /**
     * look for old contracts that start during the duration of the new contract
     *      |--old----->
     *  |--new--(|)-->.

     * @param array<int, Contract> $contracts
     */
    protected function checkOldContractsStartDateOverlap(array $contracts, \DateTime $newStartDate, ?\DateTime $newEndDate): bool
    {
        $filteredContracts = [];
        foreach ($contracts as $contract) {
            $startsAfterOrOnNewStartDate = $contract->getStart() >= $newStartDate;
            $startsBeforeOrOnNewEndDate = ($newEndDate instanceof \DateTime) ? ($contract->getStart() <= $newEndDate) : true;

            if ($startsAfterOrOnNewStartDate && $startsBeforeOrOnNewEndDate) {
                $filteredContracts[] = $contract;
            }
        }

        return (bool) $filteredContracts;
    }

    /** look for contract with ongoing end
     * |--old--|
     *      |--new----->.

     * @param array<int, Contract> $contracts
     */
    protected function checkOldContractsEndDateOverlap(array $contracts, \DateTime $newStartDate): bool
    {
        $filteredContracts = [];
        foreach ($contracts as $contract) {
            $startsBeforeOrOnNewDate = $contract->getStart() <= $newStartDate;
            $endsAfterOrOnNewDate = $contract->getEnd() >= $newStartDate;
            $hasEndDate = null !== $contract->getEnd();

            if ($startsBeforeOrOnNewDate && $endsAfterOrOnNewDate && $hasEndDate) {
                $filteredContracts[] = $contract;
            }
        }

        return (bool) $filteredContracts;
    }

    #[\Deprecated]
    public function deleteContract(Request $request): Response|Error|JsonResponse
    {
        if (false === $this->isPl($request)) {
            return $this->getFailedAuthorizationResponse();
        }

        try {
            $id = (int) $request->request->get('id');
            $doctrine = $this->doctrineRegistry;

            $contract = $doctrine->getRepository(Contract::class)
                ->find($id);

            $em = $this->doctrineRegistry->getManager();
            if ($contract) {
                $em->remove($contract);
                $em->flush();
            } else {
                throw new \RuntimeException('Already deleted');
            }
        } catch (\Exception $exception) {
            $reason = '';
            if (str_contains($exception->getMessage(), 'Integrity constraint violation')) {
                $reason = $this->translate('Other datasets refer to this one.');
            }

            $msg = sprintf($this->translate('Dataset could not be removed. %s'), $reason);

            return new Error($msg, \Symfony\Component\HttpFoundation\Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(['success' => true]);
    }
}
