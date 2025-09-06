<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\Validator\Constraints as Assert;

#[Map(target: \App\Entity\Preset::class)]
final readonly class PresetSaveDto
{
    public function __construct(
        public int $id = 0,

        #[Assert\NotBlank(message: 'Please provide a valid preset name with at least 3 letters.')]
        #[Assert\Length(min: 3, minMessage: 'Please provide a valid preset name with at least 3 letters.')]
        public string $name = '',

        /** IDs for relations; handled manually */
        #[Map(if: false)]
        public ?int $customer = null,

        #[Map(if: false)]
        public ?int $project = null,

        #[Map(if: false)]
        public ?int $activity = null,

        public string $description = '',
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        return new self(
            id: (int) ($request->request->get('id') ?? 0),
            name: (string) ($request->request->get('name') ?? ''),
            customer: null !== $request->request->get('customer') ? (int) $request->request->get('customer') : null,
            project: null !== $request->request->get('project') ? (int) $request->request->get('project') : null,
            activity: null !== $request->request->get('activity') ? (int) $request->request->get('activity') : null,
            description: (string) ($request->request->get('description') ?? ''),
        );
    }
}
