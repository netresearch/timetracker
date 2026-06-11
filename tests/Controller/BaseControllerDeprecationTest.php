<?php

declare(strict_types=1);

namespace Tests\Controller;

use App\Controller\BaseController;
use App\Entity\User;
use App\Enum\UserType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\User\UserInterface;

use function restore_error_handler;
use function set_error_handler;

use const E_USER_DEPRECATED;

/**
 * Unit tests for the deprecated BaseController helper shims.
 *
 * Verifies that each shim still delegates correctly and announces its
 * deprecation (message built by triggerMethodDeprecation()).
 *
 * @internal
 */
#[CoversClass(BaseController::class)]
final class BaseControllerDeprecationTest extends TestCase
{
    /**
     * Messages emitted via triggerMethodDeprecation(). The native
     * #[Deprecated] attribute (PHP >= 8.4) emits its own deprecation per
     * call, so only "Method ..." messages are collected.
     *
     * @var list<string>
     */
    private array $deprecations = [];

    protected function setUp(): void
    {
        $this->deprecations = [];
        set_error_handler(
            function (int $errno, string $errstr): bool {
                // triggerMethodDeprecation() uses __METHOD__ (no parentheses);
                // the native attribute formats the name as method() instead.
                if (str_starts_with($errstr, 'Method ') && !str_contains($errstr, '()')) {
                    $this->deprecations[] = $errstr;
                }

                return true;
            },
            E_USER_DEPRECATED,
        );
    }

    protected function tearDown(): void
    {
        restore_error_handler();
    }

    public function testIsLoggedInDelegatesAndDeprecates(): void
    {
        $controller = $this->createController();

        self::assertTrue($controller->callIsLoggedIn(new Request()));
        self::assertCount(1, $this->deprecations);
        self::assertStringContainsString('::isLoggedIn is deprecated', $this->deprecations[0]);
        self::assertStringContainsString("use isGranted('IS_AUTHENTICATED_FULLY') directly", $this->deprecations[0]);
    }

    public function testCheckLoginDelegatesAndDeprecates(): void
    {
        $controller = $this->createController();

        self::assertTrue($controller->callCheckLogin(new Request()));
        self::assertCount(1, $this->deprecations);
        self::assertStringContainsString('::checkLogin is deprecated', $this->deprecations[0]);
    }

    public function testHasUserTypeMatchesCurrentUserAndDeprecates(): void
    {
        $controller = $this->createController();

        self::assertTrue($controller->callHasUserType(new Request(), UserType::PL));
        self::assertFalse($controller->callHasUserType(new Request(), UserType::DEV));
        self::assertCount(2, $this->deprecations);
        self::assertStringContainsString('::hasUserType is deprecated', $this->deprecations[0]);
    }

    public function testIsPlDelegatesToHasUserTypeAndDeprecates(): void
    {
        $controller = $this->createController();

        self::assertTrue($controller->callIsPl(new Request()));
        // isPl() delegates to hasUserType(), so both shims announce themselves
        self::assertCount(2, $this->deprecations);
        self::assertStringContainsString('::isPl is deprecated', $this->deprecations[0]);
    }

    public function testIsDevDelegatesToHasUserTypeAndDeprecates(): void
    {
        $controller = $this->createController();

        self::assertFalse($controller->callIsDEV(new Request()));
        self::assertCount(2, $this->deprecations);
        self::assertStringContainsString('::isDEV is deprecated', $this->deprecations[0]);
    }

    private function createController(): object
    {
        $user = new User();
        $user->setUsername('unittest');
        $user->setType('PL');

        return new class($user) extends BaseController {
            public function __construct(private readonly User $user)
            {
            }

            public function callIsLoggedIn(Request $request): bool
            {
                return $this->isLoggedIn($request);
            }

            public function callCheckLogin(Request $request): bool
            {
                return $this->checkLogin($request);
            }

            public function callHasUserType(Request $request, UserType $userType): bool
            {
                return $this->hasUserType($request, $userType);
            }

            public function callIsPl(Request $request): bool
            {
                return $this->isPl($request);
            }

            public function callIsDEV(Request $request): bool
            {
                return $this->isDEV($request);
            }

            protected function isGranted(mixed $attribute, mixed $subject = null): bool
            {
                return true;
            }

            protected function getUser(): ?UserInterface
            {
                return $this->user;
            }
        };
    }
}
