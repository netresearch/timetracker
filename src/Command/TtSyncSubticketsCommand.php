<?php

declare(strict_types=1);

namespace App\Command;

use App\Exception\Integration\Jira\JiraApiUnauthorizedException;
use App\Service\SubticketSyncService;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use LogicException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function count;
use function is_scalar;

#[\Symfony\Component\Console\Attribute\AsCommand(name: 'tt:sync-subtickets', description: 'Update project subtickets from Jira')]
class TtSyncSubticketsCommand
{
    /**
     * @throws LogicException
     */
    public function __construct(private readonly SubticketSyncService $subticketSyncService, private readonly EntityManagerInterface $entityManager)
    {
    }

    /**
     * @throws InvalidArgumentException
     *
     * @psalm-return 0|1
     */
    public function __invoke(#[\Symfony\Component\Console\Attribute\Argument(name: 'project', description: 'Single project ID to update')]
    ?string $project, OutputInterface $output): int
    {
        $projectArg = $project;
        $symfonyStyle = new SymfonyStyle($input, $output);

        $projectId = is_scalar($projectArg) ? $projectArg : null;

        $entityRepository = $this->entityManager
            ->getRepository(\App\Entity\Project::class)
        ;
        if (null !== $projectId && '' !== $projectId) {
            $project = $entityRepository->find($projectId);
            if (!$project instanceof \App\Entity\Project) {
                $symfonyStyle->error('Project does not exist');

                return 1;
            }

            $projects = [$project];
        } else {
            $projects = $entityRepository->createQueryBuilder('p')
                ->where('p.ticketSystem IS NOT NULL')
                ->getQuery()
                ->getResult()
            ;
        }

        /** @var array<int, \App\Entity\Project> $projects */
        $count = count($projects);
        $output->writeln(
            'Found ' . $count . ' projects with ticket system',
            OutputInterface::VERBOSITY_VERBOSE,
        );
        foreach ($projects as $projectEntity) {
            $projectId = $projectEntity->getId() ?? 0;
            $projectName = $projectEntity->getName();
            $output->writeln(
                'Syncing ' . $projectId . ' ' . $projectName,
                OutputInterface::VERBOSITY_VERBOSE,
            );
            try {
                $subtickets = $this->subticketSyncService->syncProjectSubtickets($projectEntity);
            } catch (JiraApiUnauthorizedException $e) {
                throw new JiraApiUnauthorizedException($e->getMessage() . ' - project ' . $projectEntity->getName(), $e->getCode(), $e->getRedirectUrl(), $e);
            }

            $output->writeln(
                ' ' . count($subtickets) . ' subtickets found',
                OutputInterface::VERBOSITY_VERBOSE,
            );
            if ([] !== $subtickets) {
                $output->writeln(
                    ' ' . implode(',', $subtickets),
                    OutputInterface::VERBOSITY_VERY_VERBOSE,
                );
            }
        }

        return Command::SUCCESS;
    }
}
