# T-073 API v1 Session Security Plan

1. Add failing contract tests for access token validation, refresh token secrecy, route methods, replay handling and schema constraints.
2. Add a MySQL 5.7/8.0-compatible refresh-session migration.
3. Implement API v1 access-token, refresh-token and session repository services.
4. Add the v1 auth controller and exact-method bootstrap routes.
5. Add regression and integration coverage to CI.
6. Run PHP syntax, focused regressions and database matrices; request an independent code review before completion.
