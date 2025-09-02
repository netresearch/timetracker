<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\HttpFoundation\Request;

/**
 * Data-transfer object for interpretation query filters.
 */
final class InterpretationFiltersDto
{
    public ?int $customer = null;

    public ?int $customer_id = null;
    // legacy alias support
    public ?int $project = null;

    public ?int $project_id = null;
    // legacy alias support
    public ?int $user = null;

    public ?int $activity = null;

    public ?int $activity_id = null;
    // legacy alias support
    public ?int $team = null;

    public ?string $ticket = null;

    public ?string $description = null;

    public ?string $datestart = null;

    public ?string $dateend = null;

    public ?string $year = null;

    public ?string $month = null;

    public ?int $maxResults = null;

    public ?int $page = null;

    public static function fromRequest(Request $request): self
    {
        $self = new self();

        $self->customer = self::toNullableInt($request->query->get('customer'));
        $self->customer_id = self::toNullableInt($request->query->get('customer_id'));
        $self->project = self::toNullableInt($request->query->get('project'));
        $self->project_id = self::toNullableInt($request->query->get('project_id'));
        $self->user = self::toNullableInt($request->query->get('user'));
        $self->activity = self::toNullableInt($request->query->get('activity'));
        $self->activity_id = self::toNullableInt($request->query->get('activity_id'));
        $self->team = self::toNullableInt($request->query->get('team'));

        $self->ticket = self::toNullableString($request->query->get('ticket'));
        $self->description = self::toNullableString($request->query->get('description'));
        $self->datestart = self::toNullableString($request->query->get('datestart'));
        $self->dateend = self::toNullableString($request->query->get('dateend'));
        $self->year = self::toNullableString($request->query->get('year'));
        $self->month = self::toNullableString($request->query->get('month'));

        $self->maxResults = self::toNullableInt($request->query->get('maxResults'));
        $self->page = self::toNullableInt($request->query->get('page'));

        return $self;
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
