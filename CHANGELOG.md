# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.2] — 2026-05-17

### Added
- **Gutenberg block-level API**:
  - `GET /pages/{id}/blocks` — returns blocks parsed via native `parse_blocks()`
  - `PUT /pages/{id}/blocks` — replaces all blocks via `serialize_blocks()`, writes to `post_content`
- **Generalised builder cache invalidation** — `SB_Meta::invalidate_builder_caches()` now handles Breakdance, Elementor, WPBakery in one call. Triggered on either builder-meta writes or `post_content` writes.
- **Expanded cache-plugin support** — `POST /cache/purge` now supports 13 cache plugins: WP Rocket, LiteSpeed, W3 Total Cache, WP Super Cache, Cache Enabler, WP Fastest Cache, Hummingbird, SG Optimizer, Swift Performance, Comet Cache, Autoptimize, Seraphinite, WP Object Cache.
- `AGENTS.md` — protocol documentation for AI agents.
- `LICENSE`, `SECURITY.md`, `CHANGELOG.md`, `examples/`.
- `SB_Post` helper for direct SQL writes on `wp_posts`.

### Fixed
- **Critical: `wp_unslash` on `post_content`**. `wp_update_post()` strips one slash layer, corrupting Gutenberg block attribute JSON in HTML comments and any post content with backslashes. Replaced with direct SQL `UPDATE wp_posts` (`SB_Post::set_fields_raw()`) which bypasses the WP-layer.
- **`restore_backup`** now also writes `post_content` through `SB_Post` for byte-perfect restoration.
- **Auto-backup on any write**, not only meta updates. Was a bug — `PATCH` with only `content` (no `meta`) skipped the backup.

### Changed
- Plugin URI updated to GitHub.
- `sb_client.py` default config path is now `~/.sb-sites.json` (overridable via `SB_SITES_CONFIG` env var), not a hard-coded user-specific path.
- `SB_Meta::invalidate_breakdance_caches()` is now a thin alias to `invalidate_builder_caches()` (kept for backward compatibility).

## [1.0.1] — 2026-05-17

### Fixed
- **Critical: `wp_unslash` on meta values**. `update_post_meta()` / `add_post_meta()` strip one slash layer, which silently corrupts large JSON-in-meta payloads (Breakdance `_breakdance_data` ≈ 500 KB, Elementor `_elementor_data`). Replaced with direct SQL via new `SB_Meta` helper.
- Auto-invalidation of Breakdance CSS caches (meta + physical files in `wp-content/uploads/breakdance/css/`) after `_breakdance_data` writes.
- Same for restore_backup — now writes meta through `SB_Meta::insert()` (no `wp_unslash`).

## [1.0.0] — 2026-05-17

### Added
- First public release.
- HMAC-SHA256 signed REST API under `/wp-json/sb/v1/`.
- Endpoints: `/ping`, `/info`, `/audit-log`, `/error-log`, `/pages`, `/pages/{id}/backup|backups|restore`, `/plugins`, `/files`, `/cache/purge`, `/forms`.
- Defense in depth: HMAC + timestamp tolerance + IP whitelist + rate limit + audit + email alerts + kill switch.
- Tables `{prefix}sb_audit`, `{prefix}sb_page_backups`, with daily cron cleanup (90-day retention).
- Reference Python client (`sb_client.py`).
