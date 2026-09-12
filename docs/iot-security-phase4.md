# DMD IoT security configuration

Fingerprint attendance requests use HMAC-SHA256 signing. The bridge sends the exact request body bytes and these headers:

- `X-Device-Id`
- `X-Device-Timestamp` (Unix seconds)
- `X-Device-Nonce` (unique per request)
- `X-Device-Signature`

The signed canonical value is:

```text
UPPERCASE_HTTP_METHOD
REQUEST_PATH
X-Device-Timestamp
X-Device-Nonce
SHA256(raw_request_body)
```

The five lines are joined with `\n`, then signed with the HMAC-SHA256 secret configured for the device. Laravel checks the configured device ID, signature, timestamp tolerance, and a cache-backed nonce replay key before processing attendance.

The legacy `X-Device-Key` header is disabled by default. It may be enabled temporarily with `IOT_ALLOW_LEGACY_DEVICE_KEY=true` during a controlled migration, but must remain disabled in production.

The bridge control API remains bound to `127.0.0.1`. Enrollment and deletion require the separate `DMD_IOT_BRIDGE_CONTROL_KEY`. React never receives or sends that secret; Admin requests are authenticated by Laravel and proxied server-side.
