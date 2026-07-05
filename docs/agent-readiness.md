# Agent Readiness & Well-Known URIs

TimeTracker exposes a set of standard discovery affordances so LLMs and coding
agents can find and understand the application. It is a **private, authenticated**
app, so the stance is: help *authenticated* agents discover the API, and tell
*unauthenticated* crawlers there is nothing here to index or train on.

## What is served

| Path | Standard | Purpose |
|------|----------|---------|
| `/.well-known/security.txt` | [RFC 9116](https://www.rfc-editor.org/rfc/rfc9116) | Security contact; `Expires` is stamped one year out on each request so it never goes stale. |
| `/.well-known/change-password` | [W3C](https://w3c.github.io/webappsec-change-password-url/) | 302 → `/ui/settings` (the self-service password change, ADR-018). |
| `/.well-known/api-catalog` | [RFC 9727](https://www.rfc-editor.org/rfc/rfc9727) | `application/linkset+json` pointing at the OpenAPI (`service-desc`) and the help page (`service-doc`). |
| `/llms.txt` | [llmstxt.org](https://llmstxt.org/) | A concise, agent-oriented map of the app and its API. |
| `/robots.txt` | robots + [Content Signals](https://content-signals.org/) | `Content-Signal: search=no, ai-input=no, ai-train=no` on `*`, plus explicit `disallow: /` groups for GPTBot/ClaudeBot/CCBot/Google-Extended/PerplexityBot/Bytespider. |
| `Link` response headers | [RFC 8288](https://www.rfc-editor.org/rfc/rfc8288) / [RFC 8631](https://www.rfc-editor.org/rfc/rfc8631) | Every response advertises `rel="service-desc"` (the OpenAPI) and `rel="api-catalog"`. |
| JSON-LD in the SPA shell | [schema.org](https://schema.org/WebApplication) | `WebApplication` structured data, hex-escaped like `APP_CONFIG`. |

All well-known routes are public (`config/packages/security.yaml` `access_control`)
and served by `App\Controller\WellKnown\WellKnownController`; the `Link` headers
come from `App\EventSubscriber\DiscoveryLinkHeaderSubscriber`. Covered by
`tests/Controller/WellKnownControllerTest.php`.

## Crawlers vs. agents

`robots.txt` governs **unauthenticated crawlers** — and there is no public content
to crawl, so training/scraping bots are disallowed. An **authenticated AI agent**
acting for a user is unaffected: it logs in and uses the HTTP API. The two are
different audiences, and the discovery files above serve the agent while the
robots directives address the crawler.

## Programmatic access (ADR-021)

The HTTP API (`/api.yml`) accepts **scoped personal access tokens** (Bearer
`tt_pat_…`, created under Settings) in addition to the human login cookie — so an
agent can now authenticate and call it, narrowed to `resource:action` scopes. See
[ADR-021](adr/ADR-021-api-token-authentication.md) and the `bearerAuth` scheme in
`api.yml`.

### MCP server (ADR-021 Phase 5)

Coding agents (Claude Code / Cursor) use a native **MCP server** over Streamable
HTTP at `/mcp`, authenticated with the same PAT, exposing a curated set of tools
(flagship: "log time on a ticket"). Discovery: `/.well-known/mcp/server.json`.

The originally-planned `/.well-known/agent-skills.json` was **dropped** — 2026 has
no client-consumed standard for a callable-skill manifest; MCP is the convergent
one. See ADR-021 Phase 5.

The security contact in `security.txt` (`mailto:security@netresearch.de`) should be
confirmed against the actual Netresearch security channel.
