<?php
declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\HttpFoundation\Request;

final class AdminSyncDto
{
    public int $project = 0;

    public static function fromRequest(Request $request): self
    {
        $self = new self();
        $self->project = (int) ($request->query->get('project') ?? 0);
        return $self;
    }
}


