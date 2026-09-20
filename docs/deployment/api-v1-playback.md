# API v1 playback delivery

The playback endpoint is disabled by default. Enable only the playback sources that the public API may resolve, and allowlist every CDN hostname exactly.

```php
$GLOBALS['config']['app']['api_v1_playback_sources'] = array(
    'source-a' => array(
        'enabled' => true,
        'allowed_hosts' => array('cdn.example.com'),
    ),
);
$GLOBALS['config']['app']['api_v1_playback_signing'] = array(
    'enabled' => true,
    'ttl' => 300,
);
```

Set `MACCMS_API_V1_PLAYBACK_SECRET` to a random secret of at least 32 bytes in both PHP and the trusted Next.js server. Never expose this secret to browser JavaScript, public environment variables, logs, or API responses.

## Trusted signer contract

When signing is enabled, only the trusted Next.js server may issue a request to:

`GET /api/v1/videos/{public_id}/playback/{source_id}/{episode_id}?expires_at={unix_seconds}&signature={hex}`

Build the HMAC-SHA256 payload from four UTF-8 fields separated by a single newline and no trailing newline:

```text
UPPERCASE_PUBLIC_ID
source_id
episode_id
expires_at
```

Encode the digest as 64 lowercase hexadecimal characters. The expiry must be in the future and no more than the configured `ttl` seconds from PHP's verification time. Configure `ttl` between 30 and 900 seconds. The public ID, source ID, episode ID, and expiry are all bound by the signature; changing any one invalidates it.

Do not sign in a React client component. A Next.js Route Handler or Server Action may sign immediately before its server-to-server request to PHP, then return only the API result needed by the player.

## Security behavior

PHP returns a playback URL only when all of these conditions hold:

- the canonical video is published;
- the requested source exists and is enabled;
- the episode exists in the native parallel playback fields;
- the URL uses HTTPS, has no credentials, and its hostname exactly matches the source allowlist;
- DNS resolution does not point to a private, loopback, link-local, reserved, or otherwise unsafe address;
- when signing is enabled, the signature and expiry verify.

Aliases redirect to the canonical public ID. Unknown, disabled, unpublished, unsigned, expired, or unsafe requests fail closed. Keep playback responses out of shared caches and logs because the result contains a raw delivery URL.
