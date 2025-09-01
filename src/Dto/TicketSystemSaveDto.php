<?php
declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\ObjectMapper\Attribute\MapProperty;
use Symfony\Component\Validator\Constraints as Assert;

#[Map(target: \App\Entity\TicketSystem::class)]
final class TicketSystemSaveDto
{
    #[Map(if: false)]
    public ?int $id = null;

    #[Assert\NotBlank(message: 'Please provide a valid ticket system name with at least 3 letters.')]
    #[Assert\Length(min: 3, minMessage: 'Please provide a valid ticket system name with at least 3 letters.')]
    public string $name = '';

    public string $type = '';

    public bool $bookTime = false;

    public string $url = '';

    public string $login = '';

    public string $password = '';

    public string $publicKey = '';

    public string $privateKey = '';

    public string $ticketUrl = '';

    public ?string $oauthConsumerKey = null;

    public ?string $oauthConsumerSecret = null;
}


