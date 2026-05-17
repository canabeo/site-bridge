# Tests

Integration test suite that runs against a **live WordPress install** with Site Bridge active.

## Run

```bash
# Configure one site in ~/.sb-sites.json:
# {
#   "sites": {
#     "my-test-site": {
#       "url": "https://example.com",
#       "site_bridge_secret": "..."
#     }
#   }
# }

# Run the suite:
SB_TEST_SITE=my-test-site python3 tests/test_site_bridge.py

# Run smoke ping against multiple sites:
SB_TEST_SITE=my-test-site \
SB_TEST_SITES=site1,site2,site3 \
  python3 tests/test_site_bridge.py

# Use a different test page (default is 1):
SB_TEST_SITE=my-test-site SB_TEST_PAGE_ID=29 python3 tests/test_site_bridge.py
```

## What is tested (39 cases)

- **[A] Smoke** — ping each site
- **[B] System** — `/info`, `/audit-log`, `/error-log`
- **[C] Pages** — list (paginated), get, search, invalid post_type → 422, missing page → 404
- **[D] Blocks** — Gutenberg `parse_blocks` works
- **[E] Backups** — list + create manual backup, verify count increases
- **[F] Plugins** — list, verify site-bridge itself shows as active
- **[G] Cache** — purge specific target + all 13 targets
- **[H] Files** — directory listing, security: site-bridge own dir blocked, wp-config blocked, .htaccess blocked, path traversal blocked, wp-admin blocked, nonexistent → 404
- **[I] Idempotence** — no-op PATCH preserves data
- **[J] Audit** — verify test traffic shows up in audit log
- **[K] Security (5 cases)** — missing headers / non-numeric ts / expired ts / wrong sig / body tampered → each maps to the correct error code
- **[L] Rate limit** — 6th failure → 429 `sb_rate_limited`

Total: **39 assertions**. Expected: **100% pass** on a properly-installed plugin.

## Side effects

- One new row added to `wp_sb_page_backups` (manual backup, can be removed via API).
- Test IP gets banned for 1 hour at the end (intentional — verifies rate-limit). To unban early:

```sql
DELETE FROM wp_options
WHERE option_name LIKE '_transient_sb_rl_%'
   OR option_name LIKE '_transient_timeout_sb_rl_%';
```

## CI integration

A future improvement would be to run this on every PR via GitHub Actions against a disposable WP container. For now, run manually before publishing each release.
