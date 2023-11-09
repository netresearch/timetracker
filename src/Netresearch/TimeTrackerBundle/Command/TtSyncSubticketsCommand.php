<?php

namespace Netresearch\TimeTrackerBundle\Command;

use Netresearch\TimeTrackerBundle\Services\SubticketSyncService;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class TtSyncSubticketsCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('tt:sync-subtickets')
            ->setDescription('Update project subtickets from Jira')
            ->addArgument('project', InputArgument::OPTIONAL, 'Single project to update')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new SymfonyStyle($input, $output);

        $projectId = $input->getArgument('project');

        $projectRepo = $this->getContainer()->get('doctrine')
            ->getRepository('NetresearchTimeTrackerBundle:Project');
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

        $stss = new SubticketSyncService($this->getContainer());

        $output->writeln(
            'Found ' . count($projects) . ' projects with ticket system',
            OutputInterface::VERBOSITY_VERBOSE
        );
        foreach ($projects as $project) {
            $output->writeln(
                'Syncing ' . $project->getId() . ' ' . $project->getName(),
                OutputInterface::VERBOSITY_VERBOSE
            );
            $subtickets = $stss->syncProjectSubtickets($project);

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
