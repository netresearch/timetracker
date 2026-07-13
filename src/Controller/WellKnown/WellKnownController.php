<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Controller\WellKnown;

use App\Service\ClockInterface;
use DateInterval;
use DateTimeZone;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Well-known URIs (RFC 8615) and LLM/agent discovery affordances. All are public
 * (see security.yaml access_control) and read-only. See docs/agent-readiness.md.
 */
final class WellKnownController extends AbstractController
{
    private const string SECURITY_CONTACT = 'mailto:security@netresearch.de';

    public function __construct(private readonly ClockInterface $clock)
    {
    }

    /**
     * security.txt (RFC 9116). Expires is stamped one year out so it never goes
     * stale without maintenance.
     */
    #[Route(path: '/.well-known/security.txt', name: 'well_known_security_txt', methods: ['GET'])]
    public function securityTxt(Request $request): Response
    {
        $base = $request->getSchemeAndHttpHost();
        // Normalise to UTC before stamping the literal 'Z' — the clock may run in
        // the server's local timezone, which would otherwise mislabel the offset.
        $expires = $this->clock->now()
            ->add(new DateInterval('P1Y'))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s\Z');

        $body = 'Contact: ' . self::SECURITY_CONTACT . "\n"
            . "Expires: {$expires}\n"
            . "Preferred-Languages: en, de\n"
            . "Canonical: {$base}/.well-known/security.txt\n";

        return new Response($body, Response::HTTP_OK, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    /**
     * change-password (W3C Well-Known URL). Redirects to the SPA settings page,
     * whose security section performs the self-service password change (ADR-018).
     */
    #[Route(path: '/.well-known/change-password', name: 'well_known_change_password', methods: ['GET'])]
    public function changePassword(): RedirectResponse
    {
        return $this->redirect('/ui/settings/security', Response::HTTP_FOUND);
    }

    /**
     * api-catalog (RFC 9727) — a link set (RFC 9264) pointing agents at the
     * OpenAPI description and the human docs.
     */
    #[Route(path: '/.well-known/api-catalog', name: 'well_known_api_catalog', methods: ['GET'])]
    public function apiCatalog(Request $request): JsonResponse
    {
        $base = $request->getSchemeAndHttpHost();

        $response = new JsonResponse([
            'linkset' => [
                [
                    'anchor' => $base . '/',
                    'service-desc' => [
                        ['href' => $base . '/api.yml', 'type' => 'application/yaml'],
                    ],
                    'service-doc' => [
                        ['href' => $base . '/ui/help', 'type' => 'text/html'],
                    ],
                ],
            ],
        ]);
        $response->headers->set('Content-Type', 'application/linkset+json');

        return $response;
    }

    /**
     * MCP server card — points MCP-native clients (Claude Code / Cursor) at the
     * Streamable HTTP endpoint (ADR-021 Phase 5). The `/.well-known/mcp/server.json`
     * convention (SEP-1649) is still emerging; keep the shape minimal. Auth is a
     * scoped personal access token presented as `Authorization: Bearer tt_pat_…`.
     */
    #[Route(path: '/.well-known/mcp/server.json', name: 'well_known_mcp_server', methods: ['GET'])]
    public function mcpServer(Request $request): JsonResponse
    {
        $base = $request->getSchemeAndHttpHost();

        return new JsonResponse([
            'name' => 'Netresearch TimeTracker',
            'description' => 'Log and query time entries. Authenticate with a scoped personal access token (Bearer tt_pat_…) created under Settings.',
            'version' => '1.0.0',
            'remotes' => [
                ['type' => 'streamable-http', 'url' => $base . '/mcp'],
            ],
        ]);
    }

    /**
     * llms.txt (llmstxt.org) — a concise, agent-oriented map of the application.
     */
    #[Route(path: '/llms.txt', name: 'llms_txt', methods: ['GET'])]
    public function llmsTxt(Request $request): Response
    {
        $base = $request->getSchemeAndHttpHost();

        $body = <<<MD
            # TimeTracker

            > Netresearch TimeTracker: a time-tracking web application with Jira and LDAP
            > integration and XLSX export. Access requires authentication; most functionality
            > is available only to a logged-in user, so an agent needs valid credentials.

            ## API

            - [OpenAPI specification]({$base}/api.yml): the HTTP API. Humans authenticate
              with the login cookie; agents and scripts use a scoped **personal access
              token** (Bearer `tt_pat_…`, created under Settings) — see the `bearerAuth`
              scheme in the spec.
            - [API catalog]({$base}/.well-known/api-catalog): machine-readable API discovery (RFC 9727).

            ## Documentation

            - [Help and user guide]({$base}/ui/help)

            ## Security

            - [security.txt]({$base}/.well-known/security.txt)
            - [Change password]({$base}/.well-known/change-password)
            MD;

        return new Response($body . "\n", Response::HTTP_OK, ['Content-Type' => 'text/markdown; charset=utf-8']);
    }
}
