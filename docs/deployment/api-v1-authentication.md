# API v1 authentication deployment

T-073 requires a dedicated signing secret that is not shared with the legacy MACCMS JWT.

Set `MACCMS_API_V1_JWT_SECRET` in the PHP-FPM and CLI environment to at least 32 cryptographically random bytes. Alternatively, provide `$GLOBALS['config']['app']['api_v1_jwt_secret']` through the existing protected application configuration layer. Do not commit the value.

Example generation:

```sh
openssl rand -base64 48
```

Restart PHP-FPM after changing the secret. Rotation invalidates existing access tokens; device refresh sessions remain usable and issue access tokens signed by the new key. Token responses use `Cache-Control: no-store`. Refresh tokens must never be logged and should be held only by the client credential store.

Endpoints:

- `POST /api/v1/auth/login`
- `POST /api/v1/auth/refresh`
- `POST /api/v1/auth/logout`
- `GET /api/v1/auth/sessions`
- `DELETE /api/v1/auth/sessions/{session_id}`
