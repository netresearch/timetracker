<?php

namespace App\Command;

use App\Exception\Integration\Jira\JiraApiUnauthorizedException;
use App\Service\SubticketSyncService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Doctrine\ORM\EntityManagerInterface;

class TtSyncSubticketsCommand extends Command
{
    public function __construct(private readonly SubticketSyncService $subticketSyncService, private readonly EntityManagerInterface $entityManager)
    {
        parent::__construct();
    }

    protected function configure()
    {
        $this
            ->setName('tt:sync-subtickets')
            ->setDescription('Update project subtickets from Jira')
            ->addArgument('project', InputArgument::OPTIONAL, 'Single project ID to update')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $symfonyStyle = new SymfonyStyle($input, $output);

        $projectId = $input->getArgument('project');

        $entityRepository = $this->entityManager
            ->getRepository(\App\Entity\Project::class);
        if ($projectId) {
            $project = $entityRepository->find($projectId);
            if (!$project) {
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
            'Found ' . count($projects) . ' projects with ticket system',
            OutputInterface::VERBOSITY_VERBOSE
        );
        foreach ($projects as $project) {
            $output->writeln(
                'Syncing ' . $project->getId() . ' ' . $project->getName(),
                OutputInterface::VERBOSITY_VERBOSE
            );
            try {
                $subtickets = $this->subticketSyncService->syncProjectSubtickets($project);
            } catch (JiraApiUnauthorizedException $e) {
                throw new JiraApiUnauthorizedException(
                    $e->getMessage() . ' - project ' . $project->getName(),
                    $e->getCode(),
                    $e->getRedirectUrl(),
                    $e
                );
            }

            $output->writeln(
                ' ' . count($subtickets) . ' subtickets found',
                OutputInterface::VERBOSITY_VERBOSE
            );
            if ($subtickets !== []) {
                $output->writeln(
                    ' ' . implode(',', $subtickets),
                    OutputInterface::VERBOSITY_VERY_VERBOSE
                );
            }
        }

        return 0;
    }
}
