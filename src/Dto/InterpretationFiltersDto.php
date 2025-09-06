<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\HttpFoundation\Request;

use function is_scalar;

/**
 * Data-transfer object for interpretation query filters.
 */
final readonly class InterpretationFiltersDto
{
    public function __construct(
        public ?int $customer = null,

        public ?int $customer_id = null, // legacy alias support

        public ?int $project = null,

        public ?int $project_id = null, // legacy alias support

        public ?int $user = null,

        public ?int $activity = null,

        public ?int $activity_id = null, // legacy alias support

        public ?int $team = null,

        public ?string $ticket = null,

        public ?string $description = null,

        public ?string $datestart = null,

        public ?string $dateend = null,

        public ?string $year = null,

        public ?string $month = null,

        public ?int $maxResults = null,

        public ?int $page = null,
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        return new self(
            customer: self::toNullableInt($request->query->get('customer')),
            customer_id: self::toNullableInt($request->query->get('customer_id')),
            project: self::toNullableInt($request->query->get('project')),
            project_id: self::toNullableInt($request->query->get('project_id')),
            user: self::toNullableInt($request->query->get('user')),
            activity: self::toNullableInt($request->query->get('activity')),
            activity_id: self::toNullableInt($request->query->get('activity_id')),
            team: self::toNullableInt($request->query->get('team')),
            ticket: self::toNullableString($request->query->get('ticket')),
            description: self::toNullableString($request->query->get('description')),
            datestart: self::toNullableString($request->query->get('datestart')),
            dateend: self::toNullableString($request->query->get('dateend')),
            year: self::toNullableString($request->query->get('year')),
            month: self::toNullableString($request->query->get('month')),
            maxResults: self::toNullableInt($request->query->get('maxResults')),
            page: self::toNullableInt($request->query->get('page')),
        );
    }

    /**
     * Build repository filter array.
     *
     * @return array<string, mixed>
     */
    public function toFilterArray(?int $visibilityUserId, ?int $overrideMaxResults = null): array
    {
        return [
            // prefer explicit fields, fall back to legacy *_id aliases
            'customer' => $this->customer ?? $this->customer_id,
            'project' => $this->project ?? $this->project_id,
            'user' => $this->user,
            'activity' => $this->activity ?? $this->activity_id,
            'team' => $this->team,
            'ticket' => $this->ticket,
            'description' => $this->description,
            'datestart' => $this->datestart,
            'dateend' => $this->dateend,
            'visibility_user' => $visibilityUserId,
            'maxResults' => $overrideMaxResults ?? $this->maxResults,
            'page' => $this->page,
        ];
    }

    private static function toNullableInt(mixed $value): ?int
    {
        if (null === $value || '' === $value) {
            return null;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }

    private static function toNullableString(mixed $value): ?string
    {
        if (null === $value) {
            return null;
        }

        if (is_scalar($value)) {
            $s = trim((string) $value);

            return '' === $s ? null : $s;
        }

        return null;
    }
}
