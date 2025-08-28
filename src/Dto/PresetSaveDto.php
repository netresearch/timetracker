<?php
declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\ObjectMapper\Attribute\Map;

#[Map(target: \App\Entity\Preset::class)]
final class PresetSaveDto
{
    public int $id = 0;
    public string $name = '';
    /** IDs for relations; handled manually */
    #[Map(if: false)]
    public ?int $customer = null;
    #[Map(if: false)]
    public ?int $project = null;
    #[Map(if: false)]
    public ?int $activity = null;
    public string $description = '';

    public static function fromRequest(Request $request): self
    {
        $self = new self();
        $self->id = (int) ($request->request->get('id') ?? 0);
        $self->name = (string) ($request->request->get('name') ?? '');
        $self->customer = null !== $request->request->get('customer') ? (int) $request->request->get('customer') : null;
        $self->project = null !== $request->request->get('project') ? (int) $request->request->get('project') : null;
        $self->activity = null !== $request->request->get('activity') ? (int) $request->request->get('activity') : null;
        $self->description = (string) ($request->request->get('description') ?? '');
        return $self;
    }
}


