<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\HttpFoundation\Request;

final readonly class AdminSyncDto
{
    public function __construct(
        public int $project = 0,
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        return new self(
            project: (int) ($request->query->get('project') ?? 0),
        );
    }
}
