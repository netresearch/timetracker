<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\Team;
use App\Validator\Constraints\UniqueTeamName;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\Validator\Constraints as Assert;

#[Map(target: Team::class)]
final readonly class TeamSaveDto
{
    public function __construct(
        public int $id = 0,
        #[Assert\NotBlank(message: 'Please provide a valid team name with at least 3 letters.')]
        #[Assert\Length(min: 3, minMessage: 'Please provide a valid team name with at least 3 letters.')]
        #[UniqueTeamName]
        public string $name = '',
        #[Assert\NotBlank(message: 'Please provide a lead user for the team.')]
        #[Assert\Positive(message: 'Lead user ID must be valid.')]
        public int $lead_user_id = 0,
    ) {
    }

    /**
     * @throws BadRequestException
     */
    public static function fromRequest(Request $request): self
    {
        return new self(
            id: (int) ($request->request->get('id') ?? 0),
            name: (string) ($request->request->get('name') ?? ''),
            lead_user_id: (int) ($request->request->get('lead_user_id') ?? 0),
        );
    }
}
