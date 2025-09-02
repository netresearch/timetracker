<?php

declare(strict_types=1);

namespace App\Dto;

use App\Validator\Constraints\UniqueProjectNameForCustomer;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\Validator\Constraints as Assert;

#[Map(target: \App\Entity\Project::class)]
#[UniqueProjectNameForCustomer]
final class ProjectSaveDto
{
    public int $id = 0;

    #[Assert\NotBlank(message: 'Please provide a valid project name with at least 3 letters.')]
    #[Assert\Length(min: 3, minMessage: 'Please provide a valid project name with at least 3 letters.')]
    public string $name = '';

    #[Map(if: false)]
    public ?int $customer = null;

    #[Map(transform: 'strtoupper')]
    #[Assert\Regex(pattern: '/^[A-Z]+$/', message: 'The Jira prefix must contain only uppercase letters.', normalizer: 'trim')]
    public ?string $jiraId = null;

    #[Map(transform: 'strtoupper')]
    public ?string $jiraTicket = null;

    public bool $active = false;

    public bool $global = false;

    #[Map(if: false)]
    public string $estimation = '0m';

    public int $billing = 0;

    public ?string $cost_center = null;

    public ?string $offer = null;

    #[Map(if: false)]
    public ?int $project_lead = null;

    #[Map(if: false)]
    public ?int $technical_lead = null;

    #[Map(if: false)]
    public ?string $ticket_system = null;

    public bool $additionalInformationFromExternal = false;

    public ?string $internalJiraTicketSystem = null;

    public string $internalJiraProjectKey = '';

    public static function fromRequest(Request $request): self
    {
        $self = new self();
        $self->id = (int) ($request->request->get('id') ?? 0);
        $self->name = (string) ($request->request->get('name') ?? '');
        $self->customer = null !== $request->request->get('customer') ? (int) $request->request->get('customer') : null;
        $self->jiraId = (null !== $request->request->get('jiraId')) ? (string) $request->request->get('jiraId') : null;
        $self->jiraTicket = (null !== $request->request->get('jiraTicket')) ? (string) $request->request->get('jiraTicket') : null;
        $self->active = (bool) $request->request->get('active');
        $self->global = (bool) $request->request->get('global');
        $self->estimation = (string) ($request->request->get('estimation') ?? '0m');
        $self->billing = (int) ($request->request->get('billing') ?? 0);
        $self->cost_center = null !== $request->request->get('cost_center') ? (string) $request->request->get('cost_center') : null;
        $self->offer = null !== $request->request->get('offer') ? (string) $request->request->get('offer') : null;
        $self->project_lead = null !== $request->request->get('project_lead') ? (int) $request->request->get('project_lead') : null;
        $self->technical_lead = null !== $request->request->get('technical_lead') ? (int) $request->request->get('technical_lead') : null;
        $self->ticket_system = null !== $request->request->get('ticket_system') ? (string) $request->request->get('ticket_system') : null;
        $self->additionalInformationFromExternal = (bool) $request->request->get('additionalInformationFromExternal');

        $internal = $request->request->get('internalJiraTicketSystem');
        $self->internalJiraTicketSystem = ('' === $internal || null === $internal) ? null : (string) $internal;
        $self->internalJiraProjectKey = (string) $request->request->get('internalJiraProjectKey', '');

        return $self;
    }
}
