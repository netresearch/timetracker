<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Security\Csp;

use App\Security\Csp\CspNonceProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

use function base64_decode;
use function strlen;

/**
 * Unit tests for CspNonceProvider (issue #739).
 *
 * @internal
 */
#[CoversClass(CspNonceProvider::class)]
final class CspNonceProviderTest extends TestCase
{
    /**
     * The header and the rendered <script> must carry the same value, and they
     * only do so because every call within one request returns the same nonce.
     */
    public function testTheSameRequestAlwaysGetsTheSameNonce(): void
    {
        $provider = new CspNonceProvider($this->stackWith(new Request()));

        self::assertSame($provider->getNonce(), $provider->getNonce());
    }

    /**
     * A nonce reused across requests is a nonce an attacker can read off one
     * page and use on the next.
     */
    public function testADifferentRequestGetsADifferentNonce(): void
    {
        $first = new CspNonceProvider($this->stackWith(new Request()))->getNonce();
        $second = new CspNonceProvider($this->stackWith(new Request()))->getNonce();

        self::assertNotSame($first, $second);
    }

    public function testTheNonceCarriesTheExpectedEntropy(): void
    {
        $nonce = new CspNonceProvider($this->stackWith(new Request()))->getNonce();

        self::assertSame(18, strlen((string) base64_decode($nonce, true)));
    }

    /**
     * Rendering a template from a console command has no request. An empty
     * nonce matches no source expression, so the script is reported rather than
     * silently allowed — the safe direction.
     */
    public function testWithoutARequestTheNonceIsEmpty(): void
    {
        self::assertSame('', new CspNonceProvider(new RequestStack())->getNonce());
    }

    private function stackWith(Request $request): RequestStack
    {
        $stack = new RequestStack();
        $stack->push($request);

        return $stack;
    }
}
