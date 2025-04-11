<?php

declare(strict_types=1);

namespace App\Service\Ticket;

use App\Entity\Project;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Service for validating ticket formats and prefixes.
 */
class TicketValidationService
{
    /**
     * @var \Symfony\Contracts\Translation\TranslatorInterface
     */
    private $translator;

    public function __construct(TranslatorInterface $translator)
    {
        $this->translator = $translator;
    }

    /**
     * Validates a ticket format.
     *
     * @param string $ticket The ticket to validate
     * @throws \InvalidArgumentException If the ticket format is invalid
     */
    public function validateTicketFormat(string $ticket): void
    {
        // A ticket must be a valid key
        if (empty($ticket)) {
            return;
        }

        // The ticket should contain at least one hyphen
        if (strpos($ticket, '-') === false) {
            $message = $this->translator->trans('Invalid ticket format, expected PREFIX-NUMBER.');
            throw new \InvalidArgumentException($message);
        }

        // The ticket should have a structure like PROJECT-123
        $parts = explode('-', $ticket);
        if (count($parts) != 2) {
            $message = $this->translator->trans('Invalid ticket format, expected PREFIX-NUMBER.');
            throw new \InvalidArgumentException($message);
        }

        // The prefix part should be uppercase
        if ($parts[0] !== strtoupper($parts[0])) {
            $message = $this->translator->trans('Invalid ticket prefix, should be uppercase.');
            throw new \InvalidArgumentException($message);
        }

        // The number part should be numeric
        if (!is_numeric($parts[1])) {
            $message = $this->translator->trans('Invalid ticket number, should be numeric.');
            throw new \InvalidArgumentException($message);
        }
    }

    /**
     * Validates that a ticket has the correct prefix for a project.
     *
     * @param Project $project The project to validate against
     * @param string $ticket The ticket to validate
     * @throws \InvalidArgumentException If the ticket prefix doesn't match the project
     */
    public function validateTicketPrefix(Project $project, string $ticket): void
    {
        // Skip validation if ticket is empty
        if (empty($ticket)) {
            return;
        }

        // Skip validation if project has no Jira ID or Jira Ticket
        $jiraId = $project->getJiraId();
        if (empty($jiraId)) {
            return;
        }

        // Check if the ticket starts with the project's Jira ID prefix
        if (strpos($ticket, $jiraId . '-') !== 0) {
            $message = $this->translator->trans(
                'Invalid ticket prefix, expected %prefix% but got %ticket%.',
                ['%prefix%' => $jiraId, '%ticket%' => $ticket]
            );
            throw new \InvalidArgumentException($message);
        }
    }
}
