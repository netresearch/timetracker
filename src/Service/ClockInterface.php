<?php

declare(strict_types=1);

namespace App\Service;

interface ClockInterface
{
    public function now(): \DateTimeImmutable;

    public function today(): \DateTimeImmutable;
}
