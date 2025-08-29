<?php
declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\Validator\Constraints as Assert;

#\ Note: Validation handled at controller/service layer to preserve legacy HTTP codes
#[Map(target: \App\Entity\User::class)]
final class UserSaveDto
{
    public int $id = 0;
    #[Assert\NotBlank(message: 'Please provide a valid user name with at least 3 letters.')]
    #[Assert\Length(min: 3, minMessage: 'Please provide a valid user name with at least 3 letters.')]
    public string $username = '';
    #[Assert\NotBlank(message: 'Please provide a valid user name abbreviation with 3 letters.')]
    #[Assert\Length(min: 3, max: 3, minMessage: 'Please provide a valid user name abbreviation with 3 letters.', maxMessage: 'Please provide a valid user name abbreviation with 3 letters.')]
    public string $abbr = '';
    public string $type = '';
    public string $locale = '';
    /** @var list<int|string> */
    #[Map(if: false)]
    public array $teams = [];

    public static function fromRequest(Request $request): self
    {
        $self = new self();
        $self->id = (int) ($request->request->get('id') ?? 0);
        $self->username = (string) ($request->request->get('username') ?? '');
        $self->abbr = (string) ($request->request->get('abbr') ?? '');
        $self->type = (string) ($request->request->get('type') ?? '');
        $self->locale = (string) ($request->request->get('locale') ?? '');
        /** @var list<int|string> $teams */
        $teams = $request->request->all('teams') ?: [];
        $self->teams = array_values($teams);
        return $self;
    }
}



