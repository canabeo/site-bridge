# AGENTS.md — Site Bridge for AI agents

> If you are an AI agent (Claude, GPT, Gemini, etc.) helping a human manage their WordPress site(s) and they have **Site Bridge** installed, this document is for you.

This is the canonical protocol & recipe doc. It is **not** Claude-specific — any agent that can compute HMAC-SHA256 and make HTTPS requests can use Site Bridge.

---

## What you can do

Once the user has installed Site Bridge on their WordPress site(s) and given you their config (URL + secret), you can:

1. **Read** pages, posts, custom-post-types, plugins, files, audit log, error log
2. **Edit** page content — including builder-specific data (Breakdance `_breakdance_data`, Elementor `_elementor_data`, Gutenberg blocks)
3. **Write** files (in whitelisted directories — never `wp-config.php` or `.htaccess`)
4. **Install / activate / deactivate / delete** plugins
5. **Purge caches** across 13 popular WP cache plugins
6. **Auto-backup** is performed before every page edit; **rollback** any change via `/pages/{id}/restore/{backup_id}`

You **cannot** edit `wp-config.php`, `.htaccess`, `wp-admin/`, `wp-includes/`, or run arbitrary SQL — those are explicitly out of scope.

---

## Auth protocol (HMAC-SHA256)

Every request to `/wp-json/sb/v1/*` MUST include these two headers:

```
X-SB-Timestamp: <unix_seconds>
X-SB-Signature: <hex_string_lowercase>
```

The signature covers four components, joined by `"\n"`:

```
message    = TIMESTAMP + "\n" + METHOD + "\n" + PATH + "\n" + SHA256_HEX(BODY)
signature  = HMAC-SHA256(secret_bytes, message_utf8_bytes)
```

| Component | Definition |
|---|---|
| `TIMESTAMP` | Same value sent in `X-SB-Timestamp`. Must be within ±5 minutes of server clock (default tolerance). |
| `METHOD` | HTTP method, **uppercase**: `GET`, `POST`, `PUT`, `PATCH`, `DELETE` |
| `PATH` | REST route **without** the `/wp-json` prefix. Example: `/sb/v1/pages/123/blocks`. Query string is **not** included. |
| `BODY` | Raw request body bytes. For GET / no-body requests use the empty string `""`. |
| `SHA256_HEX(BODY)` | Hex-encoded SHA-256 of the body bytes (lowercase). For empty body: `e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855` |
| `secret_bytes` | UTF-8 bytes of the secret (the `SITE_BRIDGE_SECRET` value from the site's `wp-config.php`) |

The signature is **hex, lowercase**.

### Worked example

```
secret    = "test-secret-do-not-use-in-prod"
timestamp = "1779100000"
method    = "GET"
path      = "/sb/v1/ping"
body      = ""

body_sha  = sha256_hex("")
          = "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855"

message   = "1779100000\nGET\n/sb/v1/ping\ne3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855"

signature = hmac_sha256("test-secret-do-not-use-in-prod", message)
          = "53af72c5..." (your output)
```

Send:

```
GET /wp-json/sb/v1/ping HTTP/1.1
Host: example.com
X-SB-Timestamp: 1779100000
X-SB-Signature: 53af72c5...
```

### Common signing mistakes

- ❌ Including `?query=string` in `PATH` — it's NOT part of the signed message. Query parameters travel unsigned. (Don't put secrets in query strings.)
- ❌ Including `/wp-json` prefix in `PATH` — it's NOT.
- ❌ Lowercase HTTP method — must be UPPERCASE.
- ❌ Re-encoding the body before hashing (e.g. JSON-pretty-printing). The body hash is over **exactly the bytes you send**.
- ❌ Using base64 instead of hex for the signature.
- ❌ Wall-clock drift > 5 min — synchronise your clock or fetch server time from a successful `/ping` first.

### Verify your implementation

Send `GET /sb/v1/ping`. Expected 200 response:

```json
{
  "status": "ok",
  "plugin": "site-bridge",
  "version": "1.0.2",
  "wp_version": "6.x",
  "php_version": "7.4+",
  "time_utc": "2026-05-17T15:00:00+00:00",
  "site_url": "https://example.com"
}
```

If you get a 401 with code `sb_invalid_signature`, your HMAC computation is wrong. If you get `sb_expired_timestamp`, your clock is off. If you get `sb_missing_headers`, you didn't include both headers.

---

## Endpoint catalog

All endpoints are under `/wp-json/sb/v1/` and require HMAC auth.

### System

| Method | Path | Purpose |
|---|---|---|
| `GET` | `/ping` | Health check |
| `GET` | `/info` | Site info: WP/PHP versions, all installed plugins (active flag), theme, mu-plugins, server software |
| `GET` | `/audit-log?limit=&since=&auth_status=&route=` | Read audit log |
| `GET` | `/error-log?lines=200` | Tail PHP error log |

### Pages

| Method | Path | Body | Returns |
|---|---|---|---|
| `GET` | `/pages?search=&status=&per_page=&page=&post_type=` | — | Paginated list. Default post_type=`page`. `post_type='any'` not supported — use specific values. |
| `GET` | `/pages/{id}` | — | Full post incl. ALL meta values (as `meta` flat map, single values unwrapped) |
| `PATCH` | `/pages/{id}` | `{title?, slug?, status?, content?, excerpt?, meta?: {key: value, ...}, skip_backup?, notes?}` | Updated post |

`PATCH` notes:
- Auto-backup runs **before** the write, unless `skip_backup: true`
- `content` writes go through direct SQL — preserves Gutenberg block attribute JSON byte-for-byte
- `meta` writes go through direct SQL — preserves builder JSON (`_breakdance_data`, `_elementor_data`) byte-for-byte
- After write, cache invalidation runs for Breakdance / Elementor / WPBakery + WP Rocket / LiteSpeed per-post

### Backups

| Method | Path | Body | Purpose |
|---|---|---|---|
| `POST` | `/pages/{id}/backup` | `{notes?: ""}` | Manual snapshot of page state |
| `GET` | `/pages/{id}/backups` | — | List backups for a page (up to 20 most recent, older auto-pruned) |
| `POST` | `/pages/{id}/restore/{backup_id}` | — | Restore. Creates a fresh `auto-pre-restore` backup first (you can undo the restore). |

### Gutenberg blocks

| Method | Path | Body | Purpose |
|---|---|---|---|
| `GET` | `/pages/{id}/blocks` | — | Parses `post_content` via `parse_blocks()`. Returns `{blocks: [...], count, has_content}` |
| `PUT` | `/pages/{id}/blocks` | `{blocks: [...], skip_backup?, notes?}` | Replaces ALL blocks. Serialises back to `post_content` via `serialize_blocks()`. |

Block format follows native WP block structure:
```json
{
  "blockName": "core/paragraph",
  "attrs": {"align": "center"},
  "innerBlocks": [],
  "innerHTML": "<p>...</p>",
  "innerContent": ["<p>...</p>"]
}
```

### Plugins

| Method | Path | Body | Purpose |
|---|---|---|---|
| `GET` | `/plugins` | — | All plugins with `active` flag |
| `POST` | `/plugins/upload` | `{filename, zip_base64, activate?, overwrite?}` OR multipart `zip` field | Install plugin from ZIP. base64-encoded ZIP is the most reliable transport. |
| `POST` | `/plugins/{slug}/activate` | — | Activate by slug (folder name) |
| `POST` | `/plugins/{slug}/deactivate` | — | Deactivate. Note: can't deactivate site-bridge itself. |
| `DELETE` | `/plugins/{slug}` | — | Uninstall. Note: can't delete site-bridge itself. |

### Files

| Method | Path | Body | Purpose |
|---|---|---|---|
| `GET` | `/files?path={rel}` | — | Read text file. For binaries returns `content_b64`. 20 MB limit. |
| `PUT` | `/files?path={rel}` | `{content?: "..."} OR {content_b64?: "..."}, create_dirs?, mode?` | Write. Auto-backup of replaced file into `wp-content/uploads/site-bridge-backups/`. |
| `DELETE` | `/files?path={rel}` | — | Delete file. Auto-backup before. |
| `GET` | `/files/list?path={rel}` | — | List directory entries |

**Whitelisted prefixes only** (relative to ABSPATH):
- `wp-content/plugins/*` (NOT the `site-bridge/` folder — that's reserved)
- `wp-content/themes/*`
- `wp-content/uploads/*`
- `wp-content/mu-plugins/*`

Denied: `wp-admin/`, `wp-includes/`, `wp-config.php`, `.htaccess`, dotfiles at root.

### Cache

| Method | Path | Body | Purpose |
|---|---|---|---|
| `POST` | `/cache/purge` | `{targets?: ["rocket","litespeed",...], url?: "..."}` | Purge. `targets` omitted = all known plugins. `url` = single page. |

Supported plugins: `rocket`, `litespeed`, `w3tc`, `wp_super_cache`, `cache_enabler`, `wp_fastest_cache`, `hummingbird`, `sg_optimizer`, `swift_performance`, `comet_cache`, `autoptimize`, `seraphinite`, `wp`.

### Forms (optional, requires `custom-forms-sms` plugin)

| Method | Path | Purpose |
|---|---|---|
| `GET` | `/forms` | List configured forms |
| `GET` | `/forms/submissions?form_id=&since=&limit=&offset=` | List submissions |
| `GET` | `/forms/submissions/{id}` | One submission |

If the `custom-forms-sms` plugin tables don't exist, returns `{available: false}` — handle this gracefully.

---

## Recipes

### Update a single text on a Breakdance page

```python
page = sb.get_page(123)
bd_str = page['meta']['_breakdance_data']     # JSON-in-JSON string
outer = json.loads(bd_str)
tree = json.loads(outer['tree_json_string'])  # decoded tree

# walk tree recursively, find/replace
def walk(node):
    if isinstance(node, dict):
        for k, v in node.items():
            if isinstance(v, str):
                node[k] = v.replace("Old text", "New text")
            elif isinstance(v, (dict, list)):
                walk(v)
    elif isinstance(node, list):
        for item in node:
            walk(item)
walk(tree['root'])

# re-encode (preserve ensure_ascii — escape non-ASCII as \uXXXX, same as Breakdance)
new_tree_json = json.dumps(tree, ensure_ascii=True, separators=(',', ':'))
new_outer = {**outer, 'tree_json_string': new_tree_json}
new_bd = json.dumps(new_outer, ensure_ascii=True, separators=(',', ':'))

sb.update_page(123, meta={'_breakdance_data': new_bd}, notes='replace Old text')
sb.purge_cache()  # WP Rocket etc.
```

### Edit a Gutenberg block

```python
res = sb.get_blocks(123)
blocks = res['blocks']

# find the block you want to edit
for b in blocks:
    if b['blockName'] == 'core/heading' and 'Welcome' in b.get('innerHTML', ''):
        b['innerHTML'] = b['innerHTML'].replace('Welcome', 'Welcome back')
        b['innerContent'] = [c.replace('Welcome', 'Welcome back') if c else c for c in b['innerContent']]

sb.put_blocks(123, blocks)
sb.purge_cache(url="https://example.com/some-page/")
```

### Deploy a plugin update across many sites

```python
for site_key in ['prod', 'staging', 'mirror-eu', 'mirror-us']:
    sb = SiteBridge(site_key)
    result = sb.upload_plugin('/local/path/my-plugin-2.0.0.zip', activate=True, overwrite=True)
    print(f"{site_key}: {result}")
    sb.purge_cache()
```

### Roll back if something breaks

```python
# Make a change
sb.update_page(123, content="<new buggy content>")

# Verify visually... it's broken!
# List backups, pick the auto-pre-edit one
backups = sb.list_backups(123)['items']
target = next(b for b in backups if b['triggered_by'] == 'auto-pre-edit')

sb.restore_backup(123, target['id'])
sb.purge_cache(url="...")
```

---

## Best practices for agents

1. **Always check `/ping` first** before assuming the plugin is installed.
2. **Read before write** — fetch the current state, compute the diff, send a minimal patch.
3. **Pass `notes`** in every `PATCH` so the audit log is human-readable.
4. **Don't use `skip_backup: true`** unless the user explicitly asked. The backup is cheap and rollback is invaluable.
5. **Purge cache after writes** — otherwise the user won't see your change. Use `url=...` for surgical purges, or all-targets for full clear.
6. **For builder content with cyrillic / non-ASCII**, the JSON-in-meta format uses `\uXXXX` escapes. When you re-encode after edits, use the same format (`ensure_ascii=True` in Python). Otherwise the size will change harmlessly but unexpectedly.
7. **Don't leak the secret in logs or chat** — it's the master key. If you must show config to the user, mask it (`abcd…[64 chars]`).
8. **Watch for rate limit** — 5 failed auth in 5 min → IP banned for 1 hour. Don't retry blindly on 401; fix your signing first.
9. **Avoid editing the same page in rapid succession** — every PATCH creates a backup. Coalesce changes when possible.

---

## Failure modes & error codes

| HTTP | Error code | Meaning | What to do |
|---|---|---|---|
| 401 | `sb_missing_headers` | Missing X-SB-Timestamp or X-SB-Signature | Add the headers |
| 401 | `sb_invalid_timestamp` | Timestamp not a positive integer | Check your timestamp format |
| 401 | `sb_expired_timestamp` | Timestamp outside ±5 min window | Sync your clock |
| 401 | `sb_invalid_signature` | HMAC doesn't match | Recheck signing algorithm (message format, secret encoding, hex output) |
| 401 | `sb_no_secret` | `SITE_BRIDGE_SECRET` not defined in wp-config | Ask user to install/configure |
| 403 | `sb_ip_blocked` | Your IP not in whitelist | Ask user to add your IP to `SITE_BRIDGE_ALLOWED_IPS` |
| 429 | `sb_rate_limited` | Too many failed auth attempts from your IP | Wait 1 hour (default), fix your signing |
| 404 | `sb_not_found` | Resource missing | Verify the ID/path |
| 422 | `sb_validation` | Bad payload | Read the `errors` field |
| 503 | `site_bridge_disabled` | Kill switch active | Ask user to remove `SITE_BRIDGE_DISABLED` constant |
| 500 | `sb_internal` | Server-side failure | Check `/error-log?lines=50` for stack trace |

---

## Things to avoid

- ❌ **Editing `_breakdance_data` / `_elementor_data` via `update_post_meta`** (i.e. NOT going through this API). Direct WP layer applies `wp_unslash` which silently corrupts large JSON. Always go through Site Bridge.
- ❌ **Writing `post_content` via `wp_update_post`** for Gutenberg content. Same `wp_unslash` bug. Go through Site Bridge `PATCH /pages/{id}` (with `content`) or `PUT /pages/{id}/blocks`.
- ❌ **Building a new client** when the included Python `sb_client.py` already implements signing correctly. Use it as reference or wrap it.
- ❌ **Bypassing auto-backup** to save time. The backup is small (sometimes ~500 KB for a Breakdance page), the rollback is priceless when you make a mistake at 3 AM.
- ❌ **Editing `site-bridge/` plugin folder via `/files`** — explicitly blocked. Use `/plugins/upload` with overwrite for self-update.

---

## Quick checklist before using

- [ ] User has Site Bridge ≥ 1.0.2 installed
- [ ] User has shared the per-site secret with you (and only with you)
- [ ] You can sign a `GET /ping` and get HTTP 200
- [ ] You know which builder(s) the site uses (run `GET /info` to see active plugins — Breakdance / Elementor / etc.)
- [ ] You know how to roll back via `/pages/{id}/restore/{backup_id}`

If all five — you're ready to safely automate WordPress.
