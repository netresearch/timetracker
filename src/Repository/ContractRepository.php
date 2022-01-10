<?php
namespace App\Repository;

use Doctrine\ORM\EntityRepository;
use App\Entity\Contract;

/**
 * Database with all contracts
 *
 * @author Tony Kreissl <kreissl@mogic.com>
 */
class ContractRepository extends EntityRepository
{
    /**
     * Find all contracts, sorted by start ascending
     *
     * @return array Array with contract data
     */
    public function getContracts()
    {
        $contracts = $this->findBy([], ['start' => 'ASC']);
        $data = [];

        /* @var Contract $contract */
        foreach ($contracts as $contract) {
            $data[] = ['contract' => [
                'id'      => $contract->getId(),
                'user_id' => $contract->getUser()->getId(),
                'start'   => $contract->getStart()
                    ? $contract->getStart()->format('Y-m-d')
                    : null,
                'end'     => $contract->getEnd()
                    ? $contract->getEnd()->format('Y-m-d')
                    : null,
                'hours_0' => $contract->getHours0(),
                'hours_1' => $contract->getHours1(),
                'hours_2' => $contract->getHours2(),
                'hours_3' => $contract->getHours3(),
                'hours_4' => $contract->getHours4(),
                'hours_5' => $contract->getHours5(),
                'hours_6' => $contract->getHours6(),
            ]];
        }

        return $data;
    }
}
