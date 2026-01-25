<?php

declare(strict_types=1);

namespace Tests\Entity;

use App\Entity\Team;
use App\Entity\TicketSystem;
use App\Entity\User;
use App\Enum\UserType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function strlen;

/**
 * Unit tests for User entity.
 *
 * @internal
 */
#[CoversClass(User::class)]
final class UserTest extends TestCase
{
    // ==================== Constructor tests ====================

    public function testConstructorInitializesCollections(): void
    {
        $user = new User();

        self::assertCount(0, $user->getTeams());
        self::assertCount(0, $user->getContracts());
        self::assertCount(0, $user->getEntries());
        self::assertCount(0, $user->getUserTicketsystems());
    }

    // ==================== ID tests ====================

    public function testIdIsNullByDefault(): void
    {
        $user = new User();

        self::assertNull($user->getId());
    }

    public function testSetIdReturnsFluentInterface(): void
    {
        $user = new User();

        $result = $user->setId(42);

        self::assertSame($user, $result);
        self::assertSame(42, $user->getId());
    }

    // ==================== Username tests ====================

    public function testUsernameIsEmptyByDefault(): void
    {
        $user = new User();

        self::assertSame('', $user->getUsername());
    }

    public function testSetUsernameReturnsFluentInterface(): void
    {
        $user = new User();

        $result = $user->setUsername('johndoe');

        self::assertSame($user, $result);
        self::assertSame('johndoe', $user->getUsername());
    }

    // ==================== Abbr tests ====================

    public function testAbbrIsNullByDefault(): void
    {
        $user = new User();

        self::assertNull($user->getAbbr());
    }

    public function testSetAbbrReturnsFluentInterface(): void
    {
        $user = new User();

        $result = $user->setAbbr('JD');

        self::assertSame($user, $result);
        self::assertSame('JD', $user->getAbbr());
    }

    // ==================== Type tests ====================

    public function testTypeIsUserByDefault(): void
    {
        $user = new User();

        self::assertSame(UserType::USER, $user->getType());
    }

    public function testSetTypeWithEnum(): void
    {
        $user = new User();

        $result = $user->setType(UserType::DEV);

        self::assertSame($user, $result);
        self::assertSame(UserType::DEV, $user->getType());
    }

    public function testSetTypeWithString(): void
    {
        $user = new User();

        $user->setType('PL');

        self::assertSame(UserType::PL, $user->getType());
    }

    public function testSetTypeWithAdminString(): void
    {
        $user = new User();

        $user->setType('ADMIN');

        self::assertSame(UserType::ADMIN, $user->getType());
    }

    // ==================== JiraToken tests ====================

    public function testJiraTokenIsNullByDefault(): void
    {
        $user = new User();

        self::assertNull($user->getJiraToken());
    }

    public function testSetJiraTokenReturnsFluentInterface(): void
    {
        $user = new User();

        $result = $user->setJiraToken('token123');

        self::assertSame($user, $result);
        self::assertSame('token123', $user->getJiraToken());
    }

    public function testSetJiraTokenToNull(): void
    {
        $user = new User();
        $user->setJiraToken('token123');

        $user->setJiraToken(null);

        self::assertNull($user->getJiraToken());
    }

    // ==================== ShowEmptyLine tests ====================

    public function testShowEmptyLineIsFalseByDefault(): void
    {
        $user = new User();

        self::assertFalse($user->getShowEmptyLine());
    }

    public function testSetShowEmptyLineReturnsFluentInterface(): void
    {
        $user = new User();

        $result = $user->setShowEmptyLine(true);

        self::assertSame($user, $result);
        self::assertTrue($user->getShowEmptyLine());
    }

    // ==================== SuggestTime tests ====================

    public function testSuggestTimeIsTrueByDefault(): void
    {
        $user = new User();

        self::assertTrue($user->getSuggestTime());
    }

    public function testSetSuggestTimeReturnsFluentInterface(): void
    {
        $user = new User();

        $result = $user->setSuggestTime(false);

        self::assertSame($user, $result);
        self::assertFalse($user->getSuggestTime());
    }

    // ==================== ShowFuture tests ====================

    public function testShowFutureIsTrueByDefault(): void
    {
        $user = new User();

        self::assertTrue($user->getShowFuture());
    }

    public function testSetShowFutureReturnsFluentInterface(): void
    {
        $user = new User();

        $result = $user->setShowFuture(false);

        self::assertSame($user, $result);
        self::assertFalse($user->getShowFuture());
    }

    // ==================== Team tests ====================

    public function testAddTeamReturnsFluentInterface(): void
    {
        $user = new User();
        $team = new Team();

        $result = $user->addTeam($team);

        self::assertSame($user, $result);
        self::assertCount(1, $user->getTeams());
        self::assertSame($team, $user->getTeams()->first());
    }

    public function testAddMultipleTeams(): void
    {
        $user = new User();
        $team1 = new Team();
        $team2 = new Team();

        $user->addTeam($team1);
        $user->addTeam($team2);

        self::assertCount(2, $user->getTeams());
    }

    public function testResetTeamsReturnsFluentInterface(): void
    {
        $user = new User();
        $team = new Team();
        $user->addTeam($team);

        $result = $user->resetTeams();

        self::assertSame($user, $result);
        self::assertCount(0, $user->getTeams());
    }

    // ==================== Locale tests ====================

    public function testLocaleIsDeByDefault(): void
    {
        $user = new User();

        self::assertSame('de', $user->getLocale());
    }

    public function testSetLocaleNormalizesInput(): void
    {
        $user = new User();

        $result = $user->setLocale('en');

        self::assertSame($user, $result);
        self::assertSame('en', $user->getLocale());
    }

    public function testSetLocaleNormalizesLongLocale(): void
    {
        $user = new User();

        $user->setLocale('en_US');

        // LocalizationService normalizes to 2-char code
        self::assertSame('en', $user->getLocale());
    }

    // ==================== getSettings tests ====================

    public function testGetSettingsReturnsCorrectStructure(): void
    {
        $user = new User();
        $user->setId(123);
        $user->setUsername('testuser');
        $user->setShowEmptyLine(true);
        $user->setSuggestTime(false);
        $user->setShowFuture(true);
        $user->setType(UserType::DEV);
        $user->setLocale('en');

        $settings = $user->getSettings();

        self::assertSame(1, $settings['show_empty_line']);
        self::assertSame(0, $settings['suggest_time']);
        self::assertSame(1, $settings['show_future']);
        self::assertSame('testuser', $settings['user_name']);
        self::assertSame(123, $settings['user_id']);
        self::assertSame('DEV', $settings['type']);
        self::assertSame('en', $settings['locale']);
        self::assertIsArray($settings['roles']);
    }

    public function testGetSettingsWithDefaultValues(): void
    {
        $user = new User();

        $settings = $user->getSettings();

        self::assertSame(0, $settings['show_empty_line']);
        self::assertSame(1, $settings['suggest_time']);
        self::assertSame(1, $settings['show_future']);
        self::assertSame('', $settings['user_name']);
        self::assertSame(0, $settings['user_id']);
        self::assertSame('USER', $settings['type']);
        self::assertSame('de', $settings['locale']);
    }

    // ==================== getRoles tests ====================

    public function testGetRolesForUserType(): void
    {
        $user = new User();
        $user->setType(UserType::USER);

        $roles = $user->getRoles();

        self::assertContains('ROLE_USER', $roles);
    }

    public function testGetRolesForDevType(): void
    {
        $user = new User();
        $user->setType(UserType::DEV);

        $roles = $user->getRoles();

        self::assertContains('ROLE_USER', $roles);
    }

    public function testGetRolesForPlType(): void
    {
        $user = new User();
        $user->setType(UserType::PL);

        $roles = $user->getRoles();

        self::assertContains('ROLE_USER', $roles);
        self::assertContains('ROLE_PL', $roles);
        self::assertContains('ROLE_ADMIN', $roles);
    }

    public function testGetRolesForAdminType(): void
    {
        $user = new User();
        $user->setType(UserType::ADMIN);

        $roles = $user->getRoles();

        self::assertContains('ROLE_USER', $roles);
        self::assertContains('ROLE_ADMIN', $roles);
    }

    // ==================== getUserIdentifier tests ====================

    public function testGetUserIdentifierReturnsUsername(): void
    {
        $user = new User();
        $user->setUsername('johndoe');

        self::assertSame('johndoe', $user->getUserIdentifier());
    }

    public function testGetUserIdentifierReturnsUnderscoreWhenEmpty(): void
    {
        $user = new User();

        self::assertSame('_', $user->getUserIdentifier());
    }

    // ==================== eraseCredentials tests ====================

    public function testEraseCredentialsDoesNotThrow(): void
    {
        $this->expectNotToPerformAssertions();

        $user = new User();
        $user->eraseCredentials();
    }

    // ==================== getPassword tests ====================

    public function testGetPasswordReturnsHash(): void
    {
        $user = new User();
        $user->setId(1);
        $user->setUsername('testuser');

        $password = $user->getPassword();

        self::assertNotNull($password);
        self::assertSame(64, strlen($password)); // SHA-256 hex length
    }

    public function testGetPasswordIsConsistent(): void
    {
        $user = new User();
        $user->setId(1);
        $user->setUsername('testuser');

        $password1 = $user->getPassword();
        $password2 = $user->getPassword();

        self::assertSame($password1, $password2);
    }

    public function testGetPasswordDiffersForDifferentUsers(): void
    {
        $user1 = new User();
        $user1->setId(1);
        $user1->setUsername('user1');

        $user2 = new User();
        $user2->setId(2);
        $user2->setUsername('user2');

        self::assertNotSame($user1->getPassword(), $user2->getPassword());
    }

    // ==================== TicketSystem token tests ====================

    public function testGetTicketSystemAccessTokenReturnsNullWhenNoUserTicketsystem(): void
    {
        $user = new User();
        $ticketSystem = $this->createMock(TicketSystem::class);
        $ticketSystem->method('getId')->willReturn(1);

        self::assertNull($user->getTicketSystemAccessToken($ticketSystem));
    }

    public function testGetTicketSystemAccessTokenSecretReturnsNullWhenNoUserTicketsystem(): void
    {
        $user = new User();
        $ticketSystem = $this->createMock(TicketSystem::class);
        $ticketSystem->method('getId')->willReturn(1);

        self::assertNull($user->getTicketSystemAccessTokenSecret($ticketSystem));
    }

    // ==================== Collections access tests ====================

    public function testGetUserTicketsystemsReturnsCollection(): void
    {
        $user = new User();

        $result = $user->getUserTicketsystems();

        self::assertCount(0, $result);
    }

    public function testGetEntriesReturnsCollection(): void
    {
        $user = new User();

        $result = $user->getEntries();

        self::assertCount(0, $result);
    }

    public function testGetContractsReturnsCollection(): void
    {
        $user = new User();

        $result = $user->getContracts();

        self::assertCount(0, $result);
    }
}
