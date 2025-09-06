<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\HttpFoundation\Request;

use function in_array;
use function is_bool;
use function is_scalar;

final readonly class ExportQueryDto
{
    public function __construct(
        public int $userid = 0,

        public int $year = 0,

        public int $month = 0,

        public int $project = 0,

        public int $customer = 0,

        public bool $billable = false,

        public bool $tickettitles = false,
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        return new self(
            userid: self::toInt($request->query->get('userid')),
            year: self::toInt($request->query->get('year')),
            month: self::toInt($request->query->get('month')),
            project: self::toInt($request->query->get('project')),
            customer: self::toInt($request->query->get('customer')),
            billable: self::toBool($request->query->get('billable')),
            tickettitles: self::toBool($request->query->get('tickettitles')),
        );
    }

    private static function toInt(mixed $value): int
    {
        if (null === $value || '' === $value) {
            return 0;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        return 0;
    }

    private static function toBool(mixed $value): bool
    {
        if (null === $value) {
            return false;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_scalar($value)) {
            $normalized = strtolower(trim((string) $value));

            return in_array($normalized, ['1', 'true', 'on', 'yes'], true);
        }

        return false;
    }
}
