# Breaking Changes

## 2026-09-20 — v6.4.0: enforced Content Security Policy

`ContentSecurityPolicySubscriber` sets an enforcing `Content-Security-Policy` on every main-request HTML response. `script-src` names a per-request nonce and nothing else, so **any inline `<script>` without `nonce="{{ csp_nonce() }}"` is refused by the browser**.

### Impact

A stock installation is unaffected: every inline block in `templates/` carries the nonce, and the six end-to-end cases covering login, the shell, the worklog, the evaluation, the admin area and the settings sections report no violation.

A deployment with a **customised Twig shell** — an added tracking snippet, a support-chat widget, a modified `partials/header.html.twig` — loses those scripts silently from the operator's point of view; the browser console names them.

`frame-ancestors 'none'` also becomes active, so the application can no longer be framed. It still frames others, so the `APP_HEADER_URL` corporate navigation keeps working; its origin must be https, because `frame-src` refuses a plaintext one.

### Migration

Add `nonce="{{ csp_nonce() }}"` to each inline `<script>` and `<style>` you introduced. The function is available in every template. A third-party origin the application must load needs its directive extended in `App\Security\Csp\ContentSecurityPolicyBuilder`.

## 2026-09-20 — v6.4.0: the API no longer sends wildcard CORS headers

`App\Model\Response::send()` set `Access-Control-Allow-Origin: *` on roughly every API response. The header arrived in an unlabelled commit with no rationale, and let any page read whatever the API answers. It is gone, along with `send()`; the class remains as the marker type its call sites use.

### Impact

None for the shipped SPA, which is served from the same origin as the API. A **cross-origin client** — a script on another host calling this API directly — stops working.

### Migration

Cross-origin access belongs in a CORS configuration with an explicit origin list, not a wildcard. Nothing in the repository documented such a client, which is why none is configured; open an issue if your deployment has one.

## 2026-09-20 — v6.4.0: security response headers

Every response now carries `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff` and `Referrer-Policy: same-origin`, from the application and — for files served straight off disk — from `docker/nginx/*.conf`. An existing value set by a reverse proxy is never overwritten.

### Impact

`Referrer-Policy: same-origin` stops sending the referrer to other origins. An external analytics or support tool that attributed traffic by referrer no longer receives one.

## 2025-09-01 — AccessDenied subscriber and PL role attribute adoption

- Added `App\EventSubscriber\AccessDeniedSubscriber` to convert authorization denials into a legacy 403 with message "You are not allowed to perform this action." while we migrate to attribute-based security. This preserves UI/test expectations during the transition.
- Adopted `#[IsGranted('ROLE_PL')]` on selected Admin endpoints (e.g., `/team/save`, `/user/delete`). Controllers still contain explicit `isPl(...)` guards to preserve existing response codes (e.g., 422 for second delete) until tests and clients are fully updated.

Impact
- Authorization failures now pass through the subscriber and return a stable 403 with the legacy message.
- For endpoints that also keep the in-controller PL guard, business rule/status code semantics remain unchanged.

Migration Notes
- Tests should authenticate via the `loginUser` helper (already wired in `tests/AbstractWebTestCase`) and, if necessary, perform a lightweight GET to `/status/check` before protected POSTs to ensure the session cookie/token is applied.
- When moving more endpoints to attribute-only authorization, update tests to expect 403 for authz failures and keep 422/406 for validation/business rules. Consider migrating to RFC7807 for standardized payloads.

## 2025-08-28 — Authentication handling in controllers

- Controllers in `App\Controller\Default\*` now accept `#[CurrentUser] ?User` and redirect to `_login` when unauthenticated.
- Symfony Security `access_control` remains the source of truth for protection; per-action `IsGranted` attributes were removed for these endpoints to keep behavior consistent with legacy redirects.
- Tests must ensure the session cookie is applied before requesting protected routes.

### Impact
- If you were calling these endpoints without a session, you will receive a 302 redirect to `/login`.
- Code that depended on legacy `checkLogin()` should rely on Symfony Security and `#[CurrentUser]` instead.

### Test Harness Update
- The test base (`tests/AbstractWebTestCase`) now sets the `security.token_storage` token in addition to writing the token into the session.
- After calling `logInSession(...)`, perform any GET (e.g., `/status/check`) to ensure the session cookie is applied before hitting protected endpoints.

### Migration Notes
- Prefer injecting `#[CurrentUser] User $user` in controllers; fall back to `?User` with redirect for unauthenticated requests.
- Avoid manual session parsing for authentication; rely on Symfony Security.

## 2025-08-29 — Request DTO auto-validation (MapRequestPayload)

- POST endpoints in Admin controllers now use `#[MapRequestPayload]` with Symfony Validator auto-validation for request DTOs.
- On validation failures, Symfony throws `ValidationFailedException` → converted to `UnprocessableEntityHttpException` (HTTP 422).

### Impact
- Previously, validation errors returned HTTP 406 with custom messages; they now return HTTP 422 with standardized validation messages.
- Clients that depended on 406 must adapt to 422 and (optionally) parse the validation error payload.

### Affected endpoints
- `POST /user/save` (`App\Controller\Admin\SaveUserAction`)
- `POST /customer/save` (`App\Controller\Admin\SaveCustomerAction`)
- `POST /project/save` (`App\Controller\Admin\SaveProjectAction`)

### Compatibility Notes
- Uniqueness and business-rule checks remain application-specific and still return 406 where appropriate (e.g., duplicate username/abbr/customer name, missing teams).
- Only DTO constraint violations (e.g., too-short names, invalid lengths) produce 422.

### Migration Guidance
- Update client-side error handling to treat 422 as validation failure.
- Tests asserting 406 on DTO validation errors must be updated to expect 422.
- See Symfony docs for request mapping and validation:
  - Object Mapper: https://symfony.com/doc/current/object_mapper.html
  - Validation: https://symfony.com/doc/current/validation.html

## 2025-08-29 — Admin save endpoints authorization semantics

- Admin POST endpoints like `/ticketsystem/save` enforce Project Lead (PL) authorization via controller checks.
- In test and environments with cookie/session auth, a request may return 403 if the session token is not fully established on the same client before the POST.

### Impact
- Tests or clients that immediately POST after creating a session must ensure the session cookie is applied (e.g., perform a lightweight GET) or be prepared to handle HTTP 403.
- Functional tests around `ticketsystem/save` were relaxed to accept 200 or 403, while still treating 422 as validation failures and 406 as business-rule conflicts.

### Guidance
- Prefer authenticating once per client, then perform a GET to `/status/check` before protected POSTs in stateful flows.
- If using stateless APIs, switch to token-based auth to avoid session timing issues.

### Update (2025-08-29 later)
- Fixed `App\Controller\Admin\SaveTicketSystemAction` to map fields explicitly (avoid setting `id` via DTO mapper).
- No change in intended status codes: authenticated PL users receive 200 on success; DTO validation stays 422; business-rule conflicts stay 406.
- Tests now require 200 for ticket system save/update (no longer accept 403).

## 2025-09-05 — Authorization bypass fixes and test environment improvements

- Enhanced security posture: unauthorized access to protected routes now consistently returns 403 Forbidden instead of mixed redirect/error responses.
- Test environment security configuration updated to use proper form_login authentication instead of disabled security.
- Project save operations now handle null jiraId/jiraTicket values properly (previously defaulted to empty strings).

### Impact
- Applications relying on specific redirect behavior for unauthorized access may need to handle 403 responses.
- Test suites now validate proper authorization behavior with session-based authentication.
- Project jiraId and jiraTicket fields maintain their nullable database constraints correctly.

### Technical Notes
- Ticket validation in SaveEntryAction now uses project jira_id for prefix validation instead of non-existent ticket system prefix methods.
- PHP 8.4 compatibility improvements: resolved nullable parameter deprecations and ObjectMapper transform issues.

## 2025-08-30 — Admin save endpoints: error codes unified

- Unexpected exceptions during save now return 500 Internal Server Error (previously 403 Forbidden in some actions).
- Authorization failures still return 403; not-found stays 404; business-rule conflicts (e.g., duplicate names) stay 406; DTO validation failures stay 422.

Affected endpoints:
- POST /ticketsystem/save
- POST /activity/save
- POST /team/save
- POST /preset/save

### Notes
- TicketSystem create/update: forms historically submitted `id=0` on create. Server now ignores mapping `id` on create and maps only for updates. Prefer omitting `id` on create at the client.
- DTO guidance: use property-level mapping condition to avoid applying `id` on create (e.g., `#[MapProperty(if: 'intval')]`), or ignore `id` entirely during entity mapping. The controller uses `id` only to decide between create and update.

### Migration
- If you relied on 403 for generic persistence failures, adjust clients/tests to treat 500 as an unexpected server error.
