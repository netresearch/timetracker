<?php

declare(strict_types=1);

namespace App\Dto;

use App\Validator\Constraints\CustomerTeamsRequired;
use App\Validator\Constraints\UniqueCustomerName;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\Validator\Constraints as Assert;
use UnexpectedValueException;

#[Map(target: \App\Entity\Customer::class)]
#[CustomerTeamsRequired]
final readonly class CustomerSaveDto
{
    public function __construct(
        public int $id = 0,
        #[Assert\NotBlank(message: 'Please provide a valid customer name with at least 3 letters.')]
        #[Assert\Length(min: 3, minMessage: 'Please provide a valid customer name with at least 3 letters.')]
        #[UniqueCustomerName]
        public string $name = '',
        public bool $active = false,
        public bool $global = false,

        /** @var list<int|string> */
        #[Map(if: false)]
        public array $teams = [],
    ) {
    }

    /**
     * @throws \Symfony\Component\HttpFoundation\Exception\BadRequestException When request parameters are malformed
     * @throws InvalidArgumentException                                        When request data conversion fails
     * @throws UnexpectedValueException                                        When array parameter extraction fails
     */
    public static function fromRequest(Request $request): self
    {
        /** @var list<int|string> $teams */
        $teams = [] !== $request->request->all('teams') ? $request->request->all('teams') : [];

        return new self(
            id: (int) ($request->request->get('id') ?? 0),
            name: (string) ($request->request->get('name') ?? ''),
            active: (bool) $request->request->get('active'),
            global: (bool) $request->request->get('global'),
            teams: array_values($teams),
        );
    }
}
