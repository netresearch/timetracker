<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Security\ApiToken;

/**
 * Opt-out of the RequireScopeSubscriber fail-closed gate for a controller that
 * enforces scopes itself, per call, rather than via a single #[RequireScope]
 * attribute (ADR-021 Phase 5).
 *
 * The MCP endpoint multiplexes many tools, each requiring a different scope
 * checked in its handler (App\Mcp\ScopeGuard), so a single controller-level scope
 * cannot express its requirement. A controller implementing this interface takes
 * responsibility for enforcing scopes on every path — the fail-closed default
 * (deny token requests to controllers with no declared scope) is bypassed only
 * for these explicitly-marked controllers.
 */
interface SelfEnforcesScope
{
}
