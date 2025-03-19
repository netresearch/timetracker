<?php

namespace App\Command;

use App\Helper\JiraApiUnauthorizedException;
use App\Services\SubticketSyncService;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Doctrine\ORM\EntityManagerInterface;

class TtSyncSubticketsCommand extends Command
{
    private $subticketSyncService;
    private $entityManager;

    public function __construct(SubticketSyncService $subticketSyncService, EntityManagerInterface $entityManager)
    {
        parent::__construct();
        $this->subticketSyncService = $subticketSyncService;
        $this->entityManager = $entityManager;
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
        $io = new SymfonyStyle($input, $output);

        $projectId = $input->getArgument('project');

        $projectRepo = $this->entityManager
            ->getRepository(\App\Entity\Project::class);
        if ($projectId) {
            $project = $projectRepo->find($projectId);
            if (!$project) {
                $io->error('Project does not exist');
                return 1;
            }
            $projects = [$project];
        } else {
            $projects = $projectRepo->createQueryBuilder('p')
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
            if (count($subtickets)) {
                $output->writeln(
                    ' ' . implode(',', $subtickets),
                    OutputInterface::VERBOSITY_VERY_VERBOSE
                );
            }
        }

        return 0;
    }

}
