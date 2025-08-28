<?php
declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\ObjectMapper\Attribute\Map;

#[Map(target: \App\Entity\Customer::class)]
final class CustomerSaveDto
{
    public int $id = 0;
    public string $name = '';
    public bool $active = false;
    public bool $global = false;
    /** @var list<int|string> */
    #[Map(if: false)]
    public array $teams = [];

    public static function fromRequest(Request $request): self
    {
        $self = new self();
        $self->id = (int) ($request->request->get('id') ?? 0);
        $self->name = (string) ($request->request->get('name') ?? '');
        $self->active = (bool) $request->request->get('active');
        $self->global = (bool) $request->request->get('global');
        /** @var list<int|string> $teams */
        $teams = $request->request->all('teams') ?: [];
        $self->teams = array_values($teams);
        return $self;
    }
}


