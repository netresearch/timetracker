<?php

declare(strict_types=1);

namespace App\Dto;

use App\Validator\Constraints\ContractDatesValid;
use App\Validator\Constraints\ValidUser;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\Constraints as Assert;

#[ContractDatesValid]
final readonly class ContractSaveDto
{
    public function __construct(
        public int $id = 0,

        #[Assert\Positive(message: 'Please enter a valid user.')]
        #[ValidUser]
        public int $user_id = 0,

        #[Assert\NotBlank(message: 'Please enter a valid contract start.')]
        #[Assert\Regex(pattern: '/^\d{3,4}-\d{2}-\d{2}$/', message: 'Please enter a valid contract start.')]
        public string $start = '',

        public ?string $end = null,

        public float $hours_0 = 0.0,

        public float $hours_1 = 0.0,

        public float $hours_2 = 0.0,

        public float $hours_3 = 0.0,

        public float $hours_4 = 0.0,

        public float $hours_5 = 0.0,

        public float $hours_6 = 0.0,
    ) {
    }

    /**
     * @throws \Symfony\Component\HttpFoundation\Exception\BadRequestException When request parameters are malformed
     * @throws \InvalidArgumentException When request data conversion fails
     * @throws \UnexpectedValueException When numeric string conversion fails
     */
    public static function fromRequest(Request $request): self
    {
        $end = $request->request->get('end');

        return new self(
            id: (int) ($request->request->get('id') ?? 0),
            user_id: (int) ($request->request->get('user_id') ?? 0),
            start: (string) ($request->request->get('start') ?? ''),
            end: (null === $end || '' === $end) ? null : (string) $end,
            hours_0: (float) str_replace(',', '.', (string) ($request->request->get('hours_0') ?? '0')),
            hours_1: (float) str_replace(',', '.', (string) ($request->request->get('hours_1') ?? '0')),
            hours_2: (float) str_replace(',', '.', (string) ($request->request->get('hours_2') ?? '0')),
            hours_3: (float) str_replace(',', '.', (string) ($request->request->get('hours_3') ?? '0')),
            hours_4: (float) str_replace(',', '.', (string) ($request->request->get('hours_4') ?? '0')),
            hours_5: (float) str_replace(',', '.', (string) ($request->request->get('hours_5') ?? '0')),
            hours_6: (float) str_replace(',', '.', (string) ($request->request->get('hours_6') ?? '0')),
        );
    }
}
