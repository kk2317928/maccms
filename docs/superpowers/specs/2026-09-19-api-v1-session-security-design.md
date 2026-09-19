# API v1 Session Security Design

Date: 2026-09-19
Task: T-073

## Contract

- `POST /api/v1/auth/login`: username/email and password, returns a 15-minute HS256 access token and a one-time opaque refresh token.
- `POST /api/v1/auth/refresh`: atomically consumes a refresh token and returns a replacement pair.
- `POST /api/v1/auth/logout`: authenticated access token revokes its device token family.
- `GET /api/v1/auth/sessions`: lists the member's non-secret device sessions.
- `DELETE /api/v1/auth/sessions/{session_id}`: revokes one session owned by the member.

Responses use the existing API v1 envelope. Authentication failures use stable codes and never distinguish an unknown account from a wrong password.

## Tokens

Access tokens are independent from the legacy API JWT and cookie login. HS256 claims are `iss`, `aud=maccms-api-v1`, `sub`, `sid`, `jti`, `iat`, `nbf`, and `exp`. The signing secret must contain at least 32 bytes.

Refresh tokens contain 32 random bytes encoded with unpadded base64url. Only SHA-256 digests are stored. A refresh response is the only place the replacement plaintext token appears.

## Rotation and replay

Each refresh token is an immutable database row. Rotation locks the presented row, marks it consumed, and inserts its successor in one transaction. Presenting a consumed token is replay: every row in the same family is revoked. Concurrent use therefore has one winner and revokes the family when the loser is observed.

## Device sessions

A stable random public session id identifies one device. Device labels are bounded user input. User-agent and IP are stored only as keyed/one-way SHA-256 fingerprints, never as raw values. Listing exposes session id, device label, timestamps and current/revoked state only.

## Compatibility

The existing MACCMS member table and password verifier remain authoritative. Successful v1 login may lazily upgrade a legacy password hash, but it does not create legacy cookies and does not reuse the mutable `user_random` cookie-session value. Admin authentication remains isolated.
