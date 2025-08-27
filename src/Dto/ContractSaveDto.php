<?php
declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\HttpFoundation\Request;

final class ContractSaveDto
{
    public int $id = 0;
    public int $user_id = 0;
    public string $start = '';
    public ?string $end = null;
    public float $hours_0 = 0.0;
    public float $hours_1 = 0.0;
    public float $hours_2 = 0.0;
    public float $hours_3 = 0.0;
    public float $hours_4 = 0.0;
    public float $hours_5 = 0.0;
    public float $hours_6 = 0.0;

    public static function fromRequest(Request $request): self
    {
        $self = new self();
        $self->id = (int) ($request->request->get('id') ?? 0);
        $self->user_id = (int) ($request->request->get('user_id') ?? 0);
        $self->start = (string) ($request->request->get('start') ?? '');
        $end = $request->request->get('end');
        $self->end = (null === $end || '' === $end) ? null : (string) $end;
        $self->hours_0 = (float) str_replace(',', '.', (string) ($request->request->get('hours_0') ?? '0'));
        $self->hours_1 = (float) str_replace(',', '.', (string) ($request->request->get('hours_1') ?? '0'));
        $self->hours_2 = (float) str_replace(',', '.', (string) ($request->request->get('hours_2') ?? '0'));
        $self->hours_3 = (float) str_replace(',', '.', (string) ($request->request->get('hours_3') ?? '0'));
        $self->hours_4 = (float) str_replace(',', '.', (string) ($request->request->get('hours_4') ?? '0'));
        $self->hours_5 = (float) str_replace(',', '.', (string) ($request->request->get('hours_5') ?? '0'));
        $self->hours_6 = (float) str_replace(',', '.', (string) ($request->request->get('hours_6') ?? '0'));

        return $self;
    }
}



