# Breaking Changes

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
