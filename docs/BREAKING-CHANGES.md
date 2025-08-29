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
