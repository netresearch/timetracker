<?php

namespace App\Repository;

use App\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ProjectRepository extends ServiceEntityRepository
{
	public function __construct(ManagerRegistry $managerRegistry)
	{
		parent::__construct($managerRegistry, Project::class);
	}

	/**
	 * @param array|object $a
	 * @param array|object $b
	 */
	public function sortProjectsByName($a, $b): int
	{
		$aName = is_array($a) ? ($a['name'] ?? '') : (is_object($a) && property_exists($a, 'name') ? (string) $a->name : '');
		$bName = is_array($b) ? ($b['name'] ?? '') : (is_object($b) && property_exists($b, 'name') ? (string) $b->name : '');
		return strcasecmp((string) $aName, (string) $bName);
	}

	/**
	 * @return array<int, Project>
	 */
	public function getGlobalProjects(): array
	{
		/** @var array<int, Project> $list */
		$list = $this->findBy(['global' => 1]);
		return $list;
	}

	/**
	 * Returns an array structure with keys of customer IDs and an "all" key.
	 * Values are arrays of associative project arrays (id, name, jiraId, active).
	 *
	 * @return array<string|int, array<int, array{id:int,name:string,jiraId:mixed,active:bool}>>
	 */
	public function getProjectStructure(int $userId, array $customers): array
	{
		$globalProjects = $this->getGlobalProjects();
		$userProjects   = $this->getProjectsByUser($userId);

		$projects = [];
		foreach ($customers as $customer) {
			foreach ($userProjects as $userProject) {
				$up = $userProject['project'] ?? null;
				if (is_array($up) && ($customer['customer']['id'] === ($up['customer'] ?? null))) {
					$projects[$customer['customer']['id']][] = [
						'id'     => (int) ($up['id'] ?? 0),
						'name'   => (string) ($up['name'] ?? ''),
						'jiraId' => $up['jiraId'] ?? null,
						'active' => (bool) ($up['active'] ?? false),
					];
				}
			}

			foreach ($globalProjects as $globalProject) {
				$projects[$customer['customer']['id']][] = [
					'id'     => (int) $globalProject->getId(),
					'name'   => (string) $globalProject->getName(),
					'jiraId' => $globalProject->getJiraId(),
					'active' => (bool) $globalProject->getActive(),
				];
			}
		}

		foreach ($userProjects as $userProject) {
			$up = $userProject['project'] ?? null;
			if (is_array($up)) {
				$projects['all'][] = $up;
			}
		}

		foreach ($globalProjects as $globalProject) {
			$projects['all'][] = [
				'id'     => (int) $globalProject->getId(),
				'name'   => (string) $globalProject->getName(),
				'jiraId' => $globalProject->getJiraId(),
			];
		}

		foreach ($projects as &$projectList) {
			usort($projectList, $this->sortProjectsByName(...));
		}

		return $projects;
	}

	/**
	 * @return array<int, array{project: array{id:int,name:string,jiraId:mixed,active?:bool,customer?:int}}>
	 */
	public function getProjectsByUser(int $userId, int $customerId = 0): array
	{
		$queryBuilder = $this->createQueryBuilder('project')
			->where('customer.global = 1 OR user.id = :userId')
			->setParameter('userId', $userId);

		if ($customerId > 0) {
			$queryBuilder->andWhere('project.global = 1 OR customer.id = :customerId')
				->setParameter('customerId', $customerId);
		}

		/** @var Project[] $result */
		$result = $queryBuilder->leftJoin('project.customer', 'customer')
			->leftJoin('customer.teams', 'team')
			->leftJoin('team.users', 'user')
			->getQuery()
			->execute();

		$data = [];
		foreach ($result as $project) {
			if ($project instanceof Project) {
				$data[] = ['project' => $project->toArray()];
			}
		}

		return $data;
	}

	/**
	 * @return array<int, Project>
	 */
	public function findByCustomer(int $customerId = 0)
	{
		/** @var array<int, Project> $result */
		$result = $this->createQueryBuilder('project')
			->where('project.global = 1 OR customer.id = :customerId')
			->setParameter('customerId', $customerId)
			->leftJoin('project.customer', 'customer')
			->leftJoin('customer.teams', 'team')
			->leftJoin('team.users', 'user')
			->getQuery()
			->execute();

		return $result;
	}

	public function isValidJiraPrefix($jiraId): int|false
	{
		return preg_match('/^([A-Z]+[A-Z0-9]*[, ]*)*$/', (string) $jiraId);
	}
}
