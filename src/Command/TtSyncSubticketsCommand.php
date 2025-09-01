<?php
declare(strict_types=1);

namespace App\Command;

use App\Exception\Integration\Jira\JiraApiUnauthorizedException;
use App\Service\SubticketSyncService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[\Symfony\Component\Console\Attribute\AsCommand(name: 'tt:sync-subtickets', description: 'Update project subtickets from Jira')]
class TtSyncSubticketsCommand extends Command
{
    public function __construct(private readonly SubticketSyncService $subticketSyncService, private readonly EntityManagerInterface $entityManager)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('project', InputArgument::OPTIONAL, 'Single project ID to update');
    }

    /**
     * @psalm-return 0|1
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $project = $input->getArgument('project');
        $symfonyStyle = new SymfonyStyle($input, $output);

        $projectId = $project;

        $entityRepository = $this->entityManager
            ->getRepository(\App\Entity\Project::class);
        if ($projectId) {
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
                ->getResult();
        }

        $output->writeln(
            'Found '.count($projects).' projects with ticket system',
            OutputInterface::VERBOSITY_VERBOSE
        );
        foreach ($projects as $project) {
            $output->writeln(
                'Syncing '.$project->getId().' '.$project->getName(),
                OutputInterface::VERBOSITY_VERBOSE
            );
            try {
                $subtickets = $this->subticketSyncService->syncProjectSubtickets($project);
            } catch (JiraApiUnauthorizedException $e) {
                throw new JiraApiUnauthorizedException($e->getMessage().' - project '.$project->getName(), $e->getCode(), $e->getRedirectUrl(), $e);
            }

            $output->writeln(
                ' '.count($subtickets).' subtickets found',
                OutputInterface::VERBOSITY_VERBOSE
            );
            if ([] !== $subtickets) {
                $output->writeln(
                    ' '.implode(',', $subtickets),
                    OutputInterface::VERBOSITY_VERY_VERBOSE
                );
            }
        }

        return Command::SUCCESS;
    }
}
