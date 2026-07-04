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

## Deferred (paired with API-token auth)

The HTTP API (`/api.yml`) is currently **session-cookie authenticated** — an agent
can *discover and read* it but cannot authenticate programmatically. Two follow-ups
depend on adding API-token auth (a dedicated ADR):

- **`/.well-known/agent-skills.json`** ([Cloudflare Agent Skills Discovery RFC](https://github.com/cloudflare/agent-skills-discovery-rfc)) — a discovery index of `SKILL.md` skills (e.g. "log time", "query worklog"). A useful skill must be able to *call* the API, so it waits on token auth.
- **MCP server** — a thin wrapper over the token-authenticated API for MCP-native clients (Claude Desktop/Code). No new capability over the API; adds turnkey client integration.

The security contact in `security.txt` (`mailto:security@netresearch.de`) should be
confirmed against the actual Netresearch security channel.
