<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Service\Personio;

use App\DTO\Personio\EmployeeMatch;
use App\Entity\User;
use App\Service\Personio\EmployeeMatcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

#[CoversClass(EmployeeMatcher::class)]
#[CoversClass(EmployeeMatch::class)]
final class EmployeeMatcherTest extends TestCase
{
    private EmployeeMatcher $matcher;

    protected function setUp(): void
    {
        $this->matcher = new EmployeeMatcher();
    }

    public function testMatchesByEmailLocalpart(): void
    {
        $matches = $this->matcher->match(
            [$this->user(7, 'sebastian.mendel')],
            [$this->person('100', 'Sebastian', 'Mendel', 'sebastian.mendel@netresearch.de')],
        );

        self::assertCount(1, $matches);
        self::assertSame(7, $matches[0]->userId);
        self::assertSame('100', $matches[0]->personId);
        self::assertSame('Sebastian Mendel', $matches[0]->personName);
        self::assertSame('email', $matches[0]->source);
    }

    public function testFallsBackToFirstnameLastnameWhenEmailAbsent(): void
    {
        $matches = $this->matcher->match(
            [$this->user(7, 'sebastian.mendel')],
            [$this->person('100', 'Sebastian', 'Mendel', null)],
        );

        self::assertCount(1, $matches);
        self::assertSame('name', $matches[0]->source);
    }

    public function testEmailWinsOverNameAsSource(): void
    {
        $matches = $this->matcher->match(
            [$this->user(7, 'sebastian.mendel')],
            [$this->person('100', 'Sebastian', 'Mendel', 'sebastian.mendel@netresearch.de')],
        );

        self::assertSame('email', $matches[0]->source);
    }

    public function testNoMatchLeavesUserUnmatched(): void
    {
        $matches = $this->matcher->match(
            [$this->user(7, 'sebastian.mendel')],
            [$this->person('100', 'Alice', 'Adams', 'alice.adams@netresearch.de')],
        );

        self::assertSame([], $matches);
    }

    public function testAmbiguousMatchIsSkipped(): void
    {
        // Two distinct persons match the same username -> no guess.
        $matches = $this->matcher->match(
            [$this->user(7, 'sebastian.mendel')],
            [
                $this->person('100', 'Sebastian', 'Mendel', 'sebastian.mendel@netresearch.de'),
                $this->person('200', 'Sebastian', 'Mendel', null),
            ],
        );

        self::assertSame([], $matches);
    }

    public function testCaseInsensitiveMatch(): void
    {
        $matches = $this->matcher->match(
            [$this->user(7, 'Sebastian.Mendel')],
            [$this->person('100', 'Sebastian', 'Mendel', 'Sebastian.Mendel@Netresearch.de')],
        );

        self::assertCount(1, $matches);
    }

    public function testBlankUsernameIsSkipped(): void
    {
        $matches = $this->matcher->match(
            [$this->user(7, '')],
            [$this->person('100', 'Sebastian', 'Mendel', 'sebastian.mendel@netresearch.de')],
        );

        self::assertSame([], $matches);
    }

    private function user(int $id, string $username): User
    {
        $user = new User()->setUsername($username);
        $property = new ReflectionProperty(User::class, 'id');
        $property->setValue($user, $id);

        return $user;
    }

    /**
     * @return array{id: string, first_name: ?string, last_name: ?string, email: ?string}
     */
    private function person(string $id, ?string $first, ?string $last, ?string $email): array
    {
        return ['id' => $id, 'first_name' => $first, 'last_name' => $last, 'email' => $email];
    }
}
