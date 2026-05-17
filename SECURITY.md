# Security Policy

## Supported versions

Only the latest minor version receives security fixes.

| Version | Supported |
|---|---|
| 1.0.x | ✅ |
| < 1.0.0 | ❌ |

## Reporting a vulnerability

If you find a security issue, **please do not open a public issue on GitHub**. Instead:

1. Open a [private security advisory](https://github.com/canabeo/site-bridge/security/advisories/new) on this repo, or
2. Email the maintainer directly (see the `Author` field in `site-bridge.php` plugin header for contact).

Please include:
- A clear description of the vulnerability
- Steps to reproduce
- The affected version(s)
- (Optional) Suggested fix

You should expect an initial reply within 7 days. We aim to release a fix within 30 days of confirmation for high-severity issues.

## Scope

In scope:
- HMAC verification logic (auth.php)
- Rate limiting & IP whitelist
- Audit log integrity
- Path traversal / file whitelist enforcement (controller-files.php)
- Cache invalidation logic
- Backup/restore data integrity

Out of scope (use a real penetration test for these):
- WordPress core vulnerabilities — report to WordPress
- Third-party plugin vulnerabilities — report to the respective plugin author
- Misconfiguration on user's wp-config.php (e.g. weak `SITE_BRIDGE_SECRET`, missing IP whitelist)
- Hosting-layer issues (Apache, LiteSpeed, PHP itself)

## Security design notes

- **Secret never travels over the wire** — only an HMAC signature does.
- **Timestamp ±5 min** + replay protection.
- **`hash_equals()`** for timing-safe signature comparison.
- **No admin-UI configuration** — all settings live in `wp-config.php`, so a compromised wp-admin session cannot disable auth, change the secret, or whitelist new IPs.
- **File operations are whitelisted** to a small set of paths and explicitly deny `wp-config.php`, `.htaccess`, `wp-admin/*`, `wp-includes/*`.
- **Direct SQL for `_breakdance_data` / `_elementor_data` / `post_content`** uses prepared statements with `$wpdb->update`/`$wpdb->insert` — no string interpolation into SQL.

## Hardening recommendations for operators

1. Generate a long random secret: `python3 -c "import secrets; print(secrets.token_urlsafe(48))"` — minimum 48 bytes of entropy.
2. **Set `SITE_BRIDGE_ALLOWED_IPS`** to the IPs of your automation clients. Avoid leaving it empty in production.
3. Rotate the secret periodically and immediately on suspicion of compromise (see README).
4. Keep `SITE_BRIDGE_LOG_LEVEL='info'` in production (debug stores request bodies — useful only during initial setup).
5. Review `/wp-json/sb/v1/audit-log` regularly. Email alerts fire on auth-failure bursts, but human eyes catch other patterns.
6. Use HTTPS only. Site Bridge does not enforce it (WP doesn't); make sure your site is HTTPS-only at the server level.
