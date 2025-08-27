<?php
declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\BaseController;
use App\Dto\ContractSaveDto;
use App\Entity\Contract;
use App\Entity\User;
use App\Model\JsonResponse;
use App\Model\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;

final class SaveContractAction extends BaseController
{
    #[\Symfony\Component\Routing\Attribute\Route(path: '/contract/save', name: 'saveContract_attr', methods: ['POST'])]
    public function __invoke(Request $request, #[MapRequestPayload] ContractSaveDto $dto): Response|JsonResponse|\App\Response\Error
    {
        if (false === $this->isPl($request)) {
            return $this->getFailedAuthorizationResponse();
        }

        $contractId = $dto->id;
        $start = $dto->start;
        $end = $dto->end;

        /** @var User|object|null $user */
        $user = $dto->user_id ? $this->doctrineRegistry->getRepository(User::class)->find($dto->user_id) : null;

        /** @var \App\Repository\ContractRepository $objectRepository */
        $objectRepository = $this->doctrineRegistry->getRepository(Contract::class);

        if (0 !== $contractId) {
            $contract = $objectRepository->find($contractId);
            if (!$contract) {
                $message = $this->translator->trans('No entry for id.');

                return new \App\Response\Error($message, \Symfony\Component\HttpFoundation\Response::HTTP_NOT_FOUND);
            }

            if (!$contract instanceof Contract) {
                return new \App\Response\Error($this->translate('No entry for id.'), \Symfony\Component\HttpFoundation\Response::HTTP_NOT_FOUND);
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

        $dateEnd = null;
        if (null !== $end) {
            $dateEnd = \DateTime::createFromFormat('Y-m-d', $end);
            if ($dateEnd) {
                $dateEnd->setDate((int) $dateEnd->format('Y'), (int) $dateEnd->format('m'), (int) $dateEnd->format('d'));
                $dateEnd->setTime(23, 59, 59);

                if ($dateEnd < $dateStart) {
                    $response = new Response($this->translate('End date has to be greater than the start date.'));
                    $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);

                    return $response;
                }
            }
        }

        $contract->setUser($user)
            ->setStart($dateStart)
            ->setEnd($dateEnd)
            ->setHours0($dto->hours_0)
            ->setHours1($dto->hours_1)
            ->setHours2($dto->hours_2)
            ->setHours3($dto->hours_3)
            ->setHours4($dto->hours_4)
            ->setHours5($dto->hours_5)
            ->setHours6($dto->hours_6);

        $objectManager = $this->doctrineRegistry->getManager();
        $objectManager->persist($contract);

        if (0 !== $contractId) {
            $objectManager->flush();

            return new JsonResponse([$contract->getId()]);
        }

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
     * Look for existing contracts for user and update the latest if open-ended.
     * Same behavior as legacy AdminController::updateOldContract.
     */
    protected function updateOldContract(User $user, \DateTime $newStartDate, ?\DateTime $newEndDate): string
    {
        $objectManager = $this->doctrineRegistry->getManager();
        $objectRepository = $this->doctrineRegistry->getRepository(Contract::class);

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

        $contractsOld = array_filter($contractsOld, fn (Contract $contract): bool => (null === $contract->getEnd()));
        if (count($contractsOld) > 1) {
            return $this->translate('There is more than one open-ended contract for the user.');
        }

        if ([] === $contractsOld) {
            return '';
        }

        $contractOld = array_values($contractsOld)[0];

        if ($contractOld->getStart() <= $newStartDate) {
            $oldContractEndDate = clone $newStartDate;
            $contractOld->setEnd($oldContractEndDate->sub(new \DateInterval('P1D')));
            $objectManager->persist($contractOld);
            $objectManager->flush();
        }

        return '';
    }

    /** look for old contracts that start during the duration of the new contract */
    protected function checkOldContractsStartDateOverlap(array $contracts, \DateTime $newStartDate, ?\DateTime $newEndDate): bool
    {
        $filteredContracts = [];
        foreach ($contracts as $contract) {
            if (!$contract instanceof Contract) {
                continue;
            }
            $startsAfterOrOnNewStartDate = $contract->getStart() >= $newStartDate;
            $startsBeforeOrOnNewEndDate = ($newEndDate instanceof \DateTime) ? ($contract->getStart() <= $newEndDate) : true;

            if ($startsAfterOrOnNewStartDate && $startsBeforeOrOnNewEndDate) {
                $filteredContracts[] = $contract;
            }
        }

        return (bool) $filteredContracts;
    }

    /** look for contract with ongoing end */
    protected function checkOldContractsEndDateOverlap(array $contracts, \DateTime $newStartDate): bool
    {
        $filteredContracts = [];
        foreach ($contracts as $contract) {
            if (!$contract instanceof Contract) {
                continue;
            }
            $startsBeforeOrOnNewDate = $contract->getStart() <= $newStartDate;
            $endsAfterOrOnNewDate = $contract->getEnd() >= $newStartDate;
            $hasEndDate = null !== $contract->getEnd();

            if ($startsBeforeOrOnNewDate && $endsAfterOrOnNewDate && $hasEndDate) {
                $filteredContracts[] = $contract;
            }
        }

        return (bool) $filteredContracts;
    }
}



