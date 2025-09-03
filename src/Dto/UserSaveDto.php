<?php

declare(strict_types=1);

namespace App\Dto;

use App\Validator\Constraints\UniqueUserAbbr;
use App\Validator\Constraints\UniqueUsername;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

// \ Note: Validation handled at controller/service layer to preserve legacy HTTP codes
#[Map(target: \App\Entity\User::class)]
final readonly class UserSaveDto
{
    public function __construct(
        public int $id = 0,
        
        #[Assert\NotBlank(message: 'Please provide a valid user name with at least 3 letters.')]
        #[Assert\Length(min: 3, minMessage: 'Please provide a valid user name with at least 3 letters.')]
        #[UniqueUsername]
        public string $username = '',
        
        #[Assert\NotBlank(message: 'Please provide a valid user name abbreviation with 3 letters.')]
        #[Assert\Length(min: 3, max: 3, minMessage: 'Please provide a valid user name abbreviation with 3 letters.', maxMessage: 'Please provide a valid user name abbreviation with 3 letters.')]
        #[UniqueUserAbbr]
        public string $abbr = '',
        
        public string $type = '',
        
        public string $locale = '',
        
        /** @var list<int|string> */
        #[Map(if: false)]
        public array $teams = [],
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        /** @var list<int|string> $teams */
        $teams = $request->request->all('teams') ?: [];
        
        return new self(
            id: (int) ($request->request->get('id') ?? 0),
            username: (string) ($request->request->get('username') ?? ''),
            abbr: (string) ($request->request->get('abbr') ?? ''),
            type: (string) ($request->request->get('type') ?? ''),
            locale: (string) ($request->request->get('locale') ?? ''),
            teams: array_values($teams),
        );
    }

    #[Assert\Callback]
    public function validateTeams(ExecutionContextInterface $context): void
    {
        if (empty($this->teams)) {
            $context->buildViolation('Every user must belong to at least one team')
                ->atPath('teams')
                ->addViolation()
            ;
        }
    }
}
