<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Security\ApiToken;

use App\ValueObject\ApiScope;
use ReflectionMethod;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

use function is_array;
use function is_object;
use function method_exists;

/**
 * Enforces #[RequireScope] for API-token requests (ADR-021), fail-closed.
 *
 * Only ApiAccessToken (i.e. Bearer-token) requests are gated — a session/cookie
 * user is never scope-limited. For a token request the controller MUST declare a
 * #[RequireScope]; without one the request is denied, so a new data endpoint is
 * unreachable by tokens until it opts in with a scope (a coverage test guards
 * that the token-facing endpoints all declare one).
 */
final readonly class RequireScopeSubscriber implements EventSubscriberInterface
{
    public function __construct(private TokenStorageInterface $tokenStorage)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::CONTROLLER => 'onKernelController'];
    }

    public function onKernelController(ControllerEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $token = $this->tokenStorage->getToken();
        if (!$token instanceof ApiAccessToken) {
            return; // not a token request — scopes gate token auth only
        }

        $controller = $event->getController();
        $controllerObject = is_array($controller) ? $controller[0] : (is_object($controller) ? $controller : null);
        if ($controllerObject instanceof SelfEnforcesScope) {
            return; // controller enforces scopes per call (e.g. the MCP endpoint)
        }

        $required = $this->requiredScope($controller);
        // Deny by short-circuiting the controller to a generic 403 (no token/scope
        // detail leaked) rather than throwing — a thrown exception at
        // kernel.controller time trips a framework type error building the response.
        if (null === $required || !ApiScope::grants($token->getScopes(), $required)) {
            $event->setController(static fn (): JsonResponse => new JsonResponse(['error' => 'Forbidden'], Response::HTTP_FORBIDDEN));
        }
    }

    /**
     * The #[RequireScope] scope declared on the controller method (or, failing
     * that, its class), or null if none — which the caller treats as fail-closed.
     */
    private function requiredScope(callable $controller): ?string
    {
        if (is_array($controller)) {
            $reflectionMethod = new ReflectionMethod($controller[0], $controller[1]);
        } elseif (is_object($controller) && method_exists($controller, '__invoke')) {
            $reflectionMethod = new ReflectionMethod($controller, '__invoke');
        } else {
            return null;
        }

        $attributes = $reflectionMethod->getAttributes(RequireScope::class);
        if ([] === $attributes) {
            $attributes = $reflectionMethod->getDeclaringClass()->getAttributes(RequireScope::class);
        }

        return [] === $attributes ? null : $attributes[0]->newInstance()->scope;
    }
}
