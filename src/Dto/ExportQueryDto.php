<?php
declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\HttpFoundation\Request;

final class ExportQueryDto
{
    public int $userid = 0;
    public int $year = 0;
    public int $month = 0;
    public int $project = 0;
    public int $customer = 0;
    public bool $billable = false;
    public bool $tickettitles = false;

    public static function fromRequest(Request $request): self
    {
        $self = new self();
        $self->userid = self::toInt($request->query->get('userid'));
        $self->year = self::toInt($request->query->get('year'));
        $self->month = self::toInt($request->query->get('month'));
        $self->project = self::toInt($request->query->get('project'));
        $self->customer = self::toInt($request->query->get('customer'));
        $self->billable = self::toBool($request->query->get('billable'));
        $self->tickettitles = self::toBool($request->query->get('tickettitles'));

        return $self;
    }

    private static function toInt(mixed $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }
        return (int) $value;
    }

    private static function toBool(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }
        if (is_bool($value)) {
            return $value;
        }
        $normalized = strtolower(trim((string) $value));
        return in_array($normalized, ['1','true','on','yes'], true);
    }
}


