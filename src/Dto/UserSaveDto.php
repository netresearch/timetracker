<?php
declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\HttpFoundation\Request;

final class UserSaveDto
{
    public int $id = 0;
    public string $username = '';
    public string $abbr = '';
    public string $type = '';
    public string $locale = '';
    /** @var array<int|string> */
    public array $teams = [];

    public static function fromRequest(Request $request): self
    {
        $self = new self();
        $self->id = (int) ($request->request->get('id') ?? 0);
        $self->username = (string) ($request->request->get('username') ?? '');
        $self->abbr = (string) ($request->request->get('abbr') ?? '');
        $self->type = (string) ($request->request->get('type') ?? '');
        $self->locale = (string) ($request->request->get('locale') ?? '');
        /** @var array<int|string>|string|null $teams */
        $teams = $request->request->all('teams');
        $self->teams = is_array($teams) ? $teams : [];
        return $self;
    }
}



