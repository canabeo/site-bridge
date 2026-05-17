# Site Bridge

> WordPress plugin that exposes a **secure, HMAC-signed REST API** for programmatic site management. Built for autonomous AI agents and CI/CD pipelines.

**Site Bridge** lets a remote client safely:
- Read and modify pages/posts (including builder content — Breakdance, Elementor, Gutenberg blocks)
- Install / activate / deactivate plugins from a ZIP
- Read and write files inside whitelisted directories
- Purge caches across 13+ caching plugins (WP Rocket, LiteSpeed, W3TC, WP Super Cache, …)
- Read form submissions
- Inspect audit log and error log

All endpoints are protected by **HMAC-SHA256 signatures**, optional IP whitelist, rate-limit, and a kill-switch. The plugin **does not** use WordPress Application Passwords — its custom header (`X-SB-Signature`) flies under the radar of typical WAFs (NinjaFirewall, Wordfence, Imunify360) that aggressively gate password-based login attempts.

> **Status:** v1.0.2 — production-ready. Used in a 7-site network in real-world day-to-day operations. AI agents (Claude Code, GPT, etc.) interact through it via the reference Python client.

---

## Why this exists

WordPress's built-in REST API can be managed via Application Passwords + HTTP Basic Auth, but in practice that path is often broken by:
- **WAF plugins** treating every API call as a login attempt and rate-limiting it
- **`wp_unslash`** silently corrupting large JSON-in-meta payloads (Breakdance `_breakdance_data`, Elementor `_elementor_data` — see [#fix-history](#fix-history) below)
- **Apache stripping `Authorization` headers** on shared hosting
- **WPS Hide Login** breaking the login-style auth flow

Site Bridge sidesteps all of these:
- **Custom header `X-SB-Signature`** — WAFs don't recognise it as a login attempt
- **Direct SQL writes** for `wp_posts.post_content` and `wp_postmeta.meta_value` — bypass `wp_unslash` and `sanitize_meta` filters that mangle large payloads
- **Per-request HMAC** — secret never travels over the wire; replay window 5 minutes
- **Self-contained auth** — doesn't piggyback on WP's login subsystem at all

---

## Endpoints (v1.0.2)

| Group | Endpoints |
|---|---|
| **System** | `GET /ping`, `GET /info`, `GET /audit-log`, `GET /error-log` |
| **Pages** | `GET /pages`, `GET /pages/{id}`, `PATCH /pages/{id}` (with auto-backup) |
| **Backups** | `POST /pages/{id}/backup`, `GET /pages/{id}/backups`, `POST /pages/{id}/restore/{backup_id}` |
| **Gutenberg blocks** | `GET /pages/{id}/blocks`, `PUT /pages/{id}/blocks` |
| **Plugins** | `GET /plugins`, `POST /plugins/upload`, `POST /plugins/{slug}/{activate,deactivate}`, `DELETE /plugins/{slug}` |
| **Files** | `GET/PUT/DELETE /files`, `GET /files/list` (whitelisted paths only) |
| **Cache** | `POST /cache/purge` — WP Rocket, LiteSpeed, W3TC, WP Super Cache, Cache Enabler, WP Fastest Cache, Hummingbird, SG Optimizer, Swift Performance, Comet Cache, Autoptimize, Seraphinite, WP Object Cache |
| **Forms** | `GET /forms`, `GET /forms/submissions`, `GET /forms/submissions/{id}` (reads `custom-forms-sms` plugin tables — optional integration) |

REST namespace: `/wp-json/sb/v1/`

---

## Builder compatibility

Site Bridge does **not** depend on any specific page builder. Builder-specific cache invalidation kicks in only when the relevant meta keys are touched:

| Builder | Native support |
|---|---|
| **Gutenberg** | Block-level API (`/pages/{id}/blocks`), direct `post_content` writes via SQL |
| **Breakdance** | `_breakdance_data` meta editing, CSS-cache invalidation, dependency-cache cleanup |
| **Elementor** | `_elementor_data` meta editing, `_elementor_css` cache invalidation, file-cache cleanup |
| **WPBakery / Visual Composer** | `_wpb_*` meta editing, shortcode CSS cache cleanup |
| **Any other builder** | Generic `meta` PATCH works for any custom meta key |

You can have **multiple builders** on the same site — Site Bridge handles each independently.

---

## Security model

### Auth

Every request to `/wp-json/sb/v1/*` must include two headers:

```
X-SB-Timestamp: <unix-seconds>
X-SB-Signature: <hex>
```

The signature is computed as:

```
message    = TIMESTAMP + "\n" + METHOD + "\n" + PATH + "\n" + sha256_hex(BODY)
signature  = HMAC-SHA256(secret, message)   // hex, lowercase
```

Where:
- `TIMESTAMP` — the same value you sent in `X-SB-Timestamp`
- `METHOD` — uppercase HTTP method
- `PATH` — REST route **without** the `/wp-json` prefix, e.g. `/sb/v1/pages/123`
- `BODY` — raw request body bytes; empty string for GET
- `sha256_hex(BODY)` — hex-encoded SHA-256 of the body bytes

### Defense in depth

1. **HMAC** on every endpoint (including `/ping`)
2. **Timestamp tolerance** ±5 min (replay protection)
3. **IP whitelist** (optional, IPv4 CIDR supported)
4. **Rate limit**: 5 failed auth attempts in 5 min → IP banned for 1 hour, email alert
5. **Audit log** — every request (success and failure) recorded in `wp_sb_audit`
6. **Email alerts** on auth-failure bursts and dangerous operations (plugin install, file write, backup restore)
7. **Kill switch** — `define('SITE_BRIDGE_DISABLED', true);` in wp-config instantly disables all endpoints (returns 503)
8. **File whitelist** — `/files` endpoints are restricted to `wp-content/plugins/*` (except site-bridge's own dir), `wp-content/themes/*`, `wp-content/uploads/*`, `wp-content/mu-plugins/*`. `wp-config.php`, `.htaccess`, `wp-admin/`, `wp-includes/` are explicitly denied
9. **`hash_equals`** — timing-safe signature comparison
10. **Graceful secret rotation** — supports a `SITE_BRIDGE_SECRET_PREVIOUS` constant for a grace period during rotation

---

## Installation

### 1. Generate a secret

```bash
python3 -c "import secrets; print(secrets.token_urlsafe(48))"
# example: k7m9x4q...64chars...VkR3
```

### 2. Add constants to `wp-config.php`

Place these **before** `/* That's all, stop editing! */`:

```php
// REQUIRED
define( 'SITE_BRIDGE_SECRET', '<your generated secret>' );

// RECOMMENDED — restrict by client IP
define( 'SITE_BRIDGE_ALLOWED_IPS', '203.0.113.42' );      // CSV, IPv4 CIDR supported

// OPTIONAL
define( 'SITE_BRIDGE_SECRET_PREVIOUS', '' );              // for zero-downtime rotation
define( 'SITE_BRIDGE_TIMESTAMP_TOLERANCE', 300 );          // seconds
define( 'SITE_BRIDGE_ALERT_EMAIL', '' );                  // empty = admin_email
define( 'SITE_BRIDGE_LOG_LEVEL', 'info' );                // info | debug — use debug during initial setup, then switch to info
define( 'SITE_BRIDGE_DISABLED', false );                  // kill switch
```

### 3. Install the plugin

Either:
- Copy the `site-bridge/` folder to `wp-content/plugins/` via FTP/SSH, then activate via wp-admin
- Or upload `site-bridge.zip` via wp-admin → Plugins → Add New → Upload

On activation, two tables are created:
- `{prefix}sb_audit` — request log
- `{prefix}sb_page_backups` — page snapshots (auto + manual)

---

## Quick start (Python)

```python
from sb_client import SiteBridge

sb = SiteBridge("my-site")           # name from ~/.sb-sites.json

print(sb.ping())                     # {"status": "ok", ...}

# Read a page (incl. all meta — for builder content)
page = sb.get_page(123)

# Update content (works for Gutenberg)
sb.update_page(123, content="<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->")

# Update builder meta (Breakdance / Elementor)
sb.update_page(123, meta={"_breakdance_data": new_json})

# Gutenberg block-level edit
blocks = sb.get_blocks(123)["blocks"]
blocks[0]["attrs"]["align"] = "center"
sb.put_blocks(123, blocks)

# Purge caches (auto-detects what's installed)
sb.purge_cache()

# Deploy a plugin ZIP
sb.upload_plugin("/path/to/my-plugin.zip", activate=True, overwrite=True)
```

### Config file

Default location: `~/.sb-sites.json` (override via `SB_SITES_CONFIG` env var):

```json
{
  "sites": {
    "my-site": {
      "url": "https://example.com",
      "site_bridge_secret": "<same value as SITE_BRIDGE_SECRET in wp-config.php>"
    }
  }
}
```

---

## Quick start (curl)

```bash
SECRET='your-secret-here'
TS=$(date +%s)
METHOD='GET'
PATH='/sb/v1/ping'
BODY=''
BODY_SHA=$(echo -n "$BODY" | sha256sum | awk '{print $1}')
MSG=$(printf '%s\n%s\n%s\n%s' "$TS" "$METHOD" "$PATH" "$BODY_SHA")
SIG=$(echo -n "$MSG" | openssl dgst -sha256 -hmac "$SECRET" | awk '{print $2}')

curl -H "X-SB-Timestamp: $TS" -H "X-SB-Signature: $SIG" \
     "https://example.com/wp-json/sb/v1/ping"
```

---

## Reference clients

- **Python** — `sb_client.py` (included in repo)
- **JavaScript / Node.js** — see [`examples/sb_client.js`](examples/sb_client.js) — minimal HMAC client using `node:crypto`
- **AI agents** — see [`AGENTS.md`](AGENTS.md) — full LLM-friendly protocol & recipe doc

---

## Configuration reference

All configuration lives in `wp-config.php`. **No admin-UI settings** — keeping it that way prevents a compromised wp-admin from disabling auth.

| Constant | Required | Default | Purpose |
|---|---|---|---|
| `SITE_BRIDGE_SECRET` | yes | — | HMAC secret. 32+ bytes recommended (≈ 48 random urlsafe-base64 chars). |
| `SITE_BRIDGE_SECRET_PREVIOUS` | no | empty | Previous secret accepted during grace period of rotation. |
| `SITE_BRIDGE_ALLOWED_IPS` | no | empty (any) | CSV of allowed source IPs / IPv4 CIDRs. Honors `CF-Connecting-IP` and `X-Forwarded-For` headers. |
| `SITE_BRIDGE_TIMESTAMP_TOLERANCE` | no | 300 | Allowed clock skew, in seconds. |
| `SITE_BRIDGE_ALERT_EMAIL` | no | site admin_email | Recipient for security alerts. |
| `SITE_BRIDGE_LOG_LEVEL` | no | `debug` | `info` — metadata only; `debug` — also store request/response bodies (truncated to 64 KB). |
| `SITE_BRIDGE_DISABLED` | no | false | If true, all endpoints return 503. Useful as a panic switch. |

---

## Secret rotation

If you suspect the secret is compromised (or just doing routine rotation):

1. Generate a new secret.
2. Move old secret to `SITE_BRIDGE_SECRET_PREVIOUS`, put new one in `SITE_BRIDGE_SECRET`.
3. Update your client config to use the new secret.
4. After 24h (or once all clients are confirmed updated), remove `SITE_BRIDGE_SECRET_PREVIOUS`.

During the grace period, requests signed with either secret are accepted.

---

## Audit log

Every request to `/wp-json/sb/v1/*` writes a row to `{prefix}sb_audit`:

| column | description |
|---|---|
| `created_at` | UTC timestamp |
| `remote_ip` | client IP (CF-aware) |
| `method`, `route`, `query` | request line |
| `status_code` | HTTP response code |
| `auth_status` | `ok` / `invalid_signature` / `expired_timestamp` / `ip_blocked` / `rate_limited` / `missing_headers` |
| `duration_ms` | server-side processing time |
| `request_body_hash` | sha256 — body itself is **not** stored in `info` log level |
| `details` | (debug log level only) request body, response body, headers (signature redacted) |

Auto-cleanup: rows older than 90 days are removed via daily cron.

Read via API: `GET /audit-log?limit=100&since=2026-01-01&auth_status=ok`

---

## File operations

`/files` endpoints are restricted to a whitelist of directory prefixes:

- ✅ `wp-content/plugins/*` (except site-bridge's own folder — use `/plugins/upload` for self-update)
- ✅ `wp-content/themes/*`
- ✅ `wp-content/uploads/*`
- ✅ `wp-content/mu-plugins/*`

Explicitly denied:
- ❌ `wp-admin/*`, `wp-includes/*`
- ❌ `wp-config.php`, `.htaccess`, `.htpasswd`, `.env`, dotfiles at root

Each write is preceded by an automatic backup to `wp-content/uploads/site-bridge-backups/`.

Size limit: 20 MB per file.

---

## What is *not* protected by the API

Out of scope (use FTP/SSH/wp-admin):
- `wp-config.php` editing — too dangerous; secret lives here anyway
- `.htaccess` modification
- Direct DB queries (only meta/post writes through dedicated endpoints)
- WordPress core file modification

This is intentional — the smaller the API surface, the smaller the blast radius if the secret leaks.

---

## Fix history

### 1.0.2 — Gutenberg + Elementor + 13 cache plugins

- **Gutenberg block-level API**: `GET/PUT /pages/{id}/blocks` using native `parse_blocks()` / `serialize_blocks()`
- **Fix: `wp_unslash` on `post_content`**. `wp_update_post()` strips one slash layer, corrupting Gutenberg block attributes (`<!-- wp:image {"id":123,"url":"..."} -->`) and any post content with backslashes. Replaced with `SB_Post::set_fields_raw()` — direct SQL `UPDATE wp_posts` bypassing WP-layer.
- **Fix: auto-backup on any write**, not only meta updates (was a bug — content-only PATCH skipped backup).
- **Generalised builder cache invalidation**: one function now handles Breakdance, Elementor, WPBakery — and any future builder added.
- **Restore_backup** also writes through `SB_Post::set_fields_raw()` for byte-perfect restoration.
- **13 cache-plugin support**: WP Rocket, LiteSpeed, W3 Total Cache, WP Super Cache, Cache Enabler, WP Fastest Cache, Hummingbird, SG Optimizer, Swift Performance, Comet Cache, Autoptimize, Seraphinite, WP Object Cache.

### 1.0.1 — `wp_unslash` fix for meta

- **Critical**: `update_post_meta()` / `add_post_meta()` apply `wp_unslash()` to the stored value, stripping one layer of backslash escapes. For large JSON-in-meta blobs (Breakdance `_breakdance_data` ≈ 500 KB, Elementor `_elementor_data`), this silently corrupts the stored bytes (≈ 70 KB lost per typical Breakdance page). Replaced with `SB_Meta::set()` — direct SQL `INSERT/UPDATE wp_postmeta` bypassing WP-layer.
- Auto-invalidation of Breakdance CSS caches after `_breakdance_data` writes (meta + physical files in `wp-content/uploads/breakdance/css/`).

### 1.0.0

- First public release. Pages, backups, plugins, files, cache, forms, audit-log, error-log endpoints. HMAC-SHA256 auth.

---

## Contributing

Issues and pull requests welcome at https://github.com/canabeo/site-bridge.

For security issues, please email instead of opening a public issue. See [SECURITY.md](SECURITY.md).

---

## License

GPL v2 or later. See [LICENSE](LICENSE).
