<?php

declare(strict_types=1);

namespace App\Dto;

use App\Validator\Constraints\UniqueTeamName;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\Validator\Constraints as Assert;

#[Map(target: \App\Entity\Team::class)]
final class TeamSaveDto
{
    public int $id = 0;

    #[Assert\NotBlank(message: 'Please provide a valid team name with at least 3 letters.')]
    #[Assert\Length(min: 3, minMessage: 'Please provide a valid team name with at least 3 letters.')]
    #[UniqueTeamName]
    public string $name = '';

    #[Assert\NotBlank(message: 'Please provide a lead user for the team.')]
    #[Assert\Positive(message: 'Lead user ID must be valid.')]
    public int $lead_user_id = 0;

    public static function fromRequest(Request $request): self
    {
        $self = new self();
        $self->id = (int) ($request->request->get('id') ?? 0);
        $self->name = (string) ($request->request->get('name') ?? '');
        $self->lead_user_id = (int) ($request->request->get('lead_user_id') ?? 0);

        return $self;
    }
}
