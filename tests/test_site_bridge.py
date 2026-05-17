#!/usr/bin/env python3
"""
Site Bridge — integration test suite.

Runs end-to-end against a live WordPress install with Site Bridge active.
Verifies positive paths first, then negative auth paths (which trigger the
rate-limit by design — that's the final test).

USAGE:

    # 1. Set up your ~/.sb-sites.json with at least one site, e.g.:
    #    {"sites": {"test-site": {"url": "...", "site_bridge_secret": "..."}}}
    # 2. (Optionally) override path via SB_SITES_CONFIG env var
    # 3. Run:
    SB_TEST_SITE=test-site python3 tests/test_site_bridge.py

    # To run smoke tests against multiple sites:
    SB_TEST_SITE=test-site SB_TEST_SITES=site1,site2,site3 python3 tests/test_site_bridge.py

REQUIREMENTS:
    - Python 3.8+
    - sb_client.py importable (same directory or PYTHONPATH)
    - At least page ID 1 must exist (default page on fresh WP install)

SIDE EFFECTS (all safe / reversible):
    - Creates one manual backup entry for the test page
    - Cycles auth attempts that trigger rate-limit ban (1 hour, on test IP only)

NOTE:
    After running, the test IP will be rate-limit-banned for ~1 hour due to the
    auth-failure tests at the end. This is intentional — it verifies the ban
    mechanism actually works. To unban early, restart the WP transient cache or
    DELETE wp_options WHERE option_name LIKE '_transient_sb_rl_%'.
"""

import os, sys, json, time, hashlib, hmac
from pathlib import Path

# Allow running from anywhere — add parent dir to path so sb_client imports
sys.path.insert(0, str(Path(__file__).resolve().parent.parent))
from sb_client import SiteBridge, SiteBridgeError

from urllib import request as urlrequest
from urllib.error import HTTPError


class TestRunner:
    def __init__(self):
        self.passed = self.failed = 0
        self.failures = []

    def run(self, name, fn):
        try:
            result = fn()
            print(f"  ✅ {name:<58s} {result or 'ok'}")
            self.passed += 1
        except AssertionError as e:
            print(f"  ❌ {name:<58s} FAIL: {e or '(no msg)'}")
            self.failed += 1
            self.failures.append((name, str(e)))
        except SiteBridgeError as e:
            print(f"  ❌ {name:<58s} HTTP {e.status} {e.code}")
            self.failed += 1
            self.failures.append((name, f"HTTP {e.status} {e.code}: {e.message}"))
        except Exception as e:
            print(f"  ⚠️  {name:<58s} {type(e).__name__}: {e}")
            self.failed += 1
            self.failures.append((name, f"{type(e).__name__}: {e}"))

    def done(self):
        total = self.passed + self.failed
        pct = 100 * self.passed / max(1, total)
        print(f"\n{'═' * 72}")
        print(f"  Passed: {self.passed}/{total} ({pct:.0f}%)   Failed: {self.failed}")
        print(f"{'═' * 72}")
        if self.failures:
            print("\nFailure details:")
            for name, err in self.failures:
                print(f"    - {name}: {err}")
        return self.failed == 0


def raw_request(base_url, method, path, headers=None, body=b""):
    """Direct HTTP without HMAC signing — for auth-failure tests."""
    url = base_url.rstrip("/") + "/wp-json" + path
    req = urlrequest.Request(url, data=body if body else None,
                              method=method, headers=headers or {})
    try:
        with urlrequest.urlopen(req, timeout=15) as r:
            return r.status, json.loads(r.read().decode("utf-8"))
    except HTTPError as e:
        raw = e.read()
        try:
            return e.code, json.loads(raw.decode("utf-8"))
        except Exception:
            return e.code, {"raw": raw[:200]}


def main():
    main_site = os.environ.get("SB_TEST_SITE")
    if not main_site:
        print("ERROR: set SB_TEST_SITE env var to a key from ~/.sb-sites.json")
        return 2
    smoke_sites = os.environ.get("SB_TEST_SITES", main_site).split(",")
    test_page_id = int(os.environ.get("SB_TEST_PAGE_ID", "1"))

    t = TestRunner()
    sb = SiteBridge(main_site)

    # ═══════════════ Positive tests (don't trigger rate-limit) ═══════════════

    print("\n[A] Smoke — ping configured site(s)")
    for site in smoke_sites:
        site = site.strip()
        def _ping(site=site):
            r = SiteBridge(site).ping()
            assert r["status"] == "ok", f"status={r.get('status')}"
            assert "version" in r
            return f"v={r['version']}, wp={r.get('wp_version')}"
        t.run(f"ping {site}", _ping)

    print("\n[B] System")
    def _info():
        r = sb.info()
        assert any(p["slug"] == "site-bridge" and p["active"] for p in r["plugins"])
        return f"{len(r['plugins'])} plugins"
    t.run("GET /info", _info)
    t.run("GET /audit-log", lambda: f"{sb.audit(limit=5)['count']} records")
    t.run("GET /error-log", lambda: "readable" if "content" in sb.error_log(lines=5) else "n/a")

    print("\n[C] Pages")
    def _list():
        r = sb.list_pages(per_page=10)
        assert r["count"] > 0
        return f"{r['total']} total"
    t.run("GET /pages", _list)
    def _get():
        r = sb.get_page(test_page_id)
        assert r["id"] == test_page_id
        return f"id={test_page_id}"
    t.run(f"GET /pages/{test_page_id}", _get)
    def _invalid_pt():
        try:
            sb.list_pages(post_type="xyz_invalid_type")
            assert False, "expected 422"
        except SiteBridgeError as e:
            assert e.status == 422 and e.code == "sb_validation"
    t.run("GET /pages?post_type=invalid → 422", _invalid_pt)
    def _404():
        try:
            sb.get_page(99999999)
            assert False
        except SiteBridgeError as e:
            assert e.status == 404
    t.run("GET /pages/99999999 → 404", _404)

    print("\n[D] Blocks (Gutenberg)")
    def _blocks():
        r = sb.get_blocks(test_page_id)
        assert "blocks" in r and "count" in r
        return f"{r['count']} blocks"
    t.run(f"GET /pages/{test_page_id}/blocks", _blocks)

    print("\n[E] Backups")
    def _bl():
        return f"{sb.list_backups(test_page_id)['count']} existing"
    t.run(f"GET /pages/{test_page_id}/backups", _bl)
    def _bc():
        pre = sb.list_backups(test_page_id)
        r = sb.backup_page(test_page_id, notes="test suite")
        assert r["created"]
        post = sb.list_backups(test_page_id)
        assert post["count"] == pre["count"] + 1
        return f"id={r['backup_id']}, count {pre['count']}→{post['count']}"
    t.run(f"POST /pages/{test_page_id}/backup", _bc)

    print("\n[F] Plugins")
    def _pl():
        r = sb.list_plugins()
        me = next(p for p in r["items"] if p["slug"] == "site-bridge")
        assert me["active"]
        return f"{r['count']} plugins, site-bridge v{me['version']}"
    t.run("GET /plugins", _pl)

    print("\n[G] Cache")
    def _pwp():
        r = sb.purge_cache(targets=["wp"])
        assert r["purged"]
        return r["results"]["wp"]
    t.run("POST /cache/purge {wp}", _pwp)
    def _pall():
        r = sb.purge_cache()
        inst = sum(1 for v in r["results"].values() if v != "not installed")
        return f"{inst}/{len(r['results'])} plugins purged"
    t.run("POST /cache/purge (auto-detect)", _pall)

    print("\n[H] Files — whitelist enforcement")
    def _list_plugins_dir():
        r = sb.list_directory("wp-content/plugins")
        assert any(e["name"] == "site-bridge" for e in r["entries"])
        return f"{r['count']} entries"
    t.run("GET /files/list wp-content/plugins", _list_plugins_dir)
    def _own_blocked():
        try:
            sb.read_file("wp-content/plugins/site-bridge/site-bridge.php")
            assert False
        except SiteBridgeError as e:
            assert e.status == 422 and e.code == "sb_validation"
    t.run("read site-bridge own dir → 422 (security)", _own_blocked)
    def _wpconfig():
        try:
            sb.read_file("wp-config.php")
            assert False
        except SiteBridgeError as e:
            assert e.status in (422, 403), f"got {e.status}"
    t.run("read wp-config.php → blocked (422 or 403)", _wpconfig)
    def _htaccess():
        try:
            sb.read_file(".htaccess")
            assert False
        except SiteBridgeError as e:
            assert e.status in (422, 403)
    t.run("read .htaccess → blocked", _htaccess)
    def _traversal():
        try:
            sb.read_file("wp-content/../wp-config.php")
            assert False
        except SiteBridgeError as e:
            assert e.status in (422, 403)
    t.run("path traversal '..' → blocked", _traversal)
    def _admin():
        try:
            sb.read_file("wp-admin/index.php")
            assert False
        except SiteBridgeError as e:
            assert e.status == 422
    t.run("read wp-admin/ → 422", _admin)

    print("\n[I] Idempotence")
    def _idem():
        p = sb.get_page(test_page_id)
        sb.update_page(test_page_id, title=p["title"], skip_backup=True, notes="idempotence")
        p2 = sb.get_page(test_page_id)
        assert p2["title"] == p["title"]
    t.run("PATCH no-op preserves data", _idem)

    print("\n[J] Audit log records the test calls")
    def _audit_chk():
        r = sb.audit(limit=10, auth_status="ok")
        return f"{len(r['records'])} recent ok requests"
    t.run("audit contains test traffic", _audit_chk)

    # ═══════════════ Security tests (TRIGGERS RATE LIMIT — last) ═══════════════

    print("\n[K] Auth security — 5 failures trigger rate-limit ban")
    base = sb.base_url

    def _miss():
        s, b = raw_request(base, "GET", "/sb/v1/ping")
        assert s == 401 and b.get("code") == "sb_missing_headers"
    t.run("missing headers → sb_missing_headers", _miss)

    def _bts():
        s, b = raw_request(base, "GET", "/sb/v1/ping",
            {"X-SB-Timestamp": "abc", "X-SB-Signature": "00"})
        assert s == 401 and b.get("code") == "sb_invalid_timestamp"
    t.run("non-numeric ts → sb_invalid_timestamp", _bts)

    def _ots():
        s, b = raw_request(base, "GET", "/sb/v1/ping",
            {"X-SB-Timestamp": "1700000000", "X-SB-Signature": "de"})
        assert s == 401 and b.get("code") == "sb_expired_timestamp"
    t.run("expired ts → sb_expired_timestamp", _ots)

    def _bsig():
        ts = str(int(time.time()))
        s, b = raw_request(base, "GET", "/sb/v1/ping",
            {"X-SB-Timestamp": ts, "X-SB-Signature": "0" * 64})
        assert s == 401 and b.get("code") == "sb_invalid_signature"
    t.run("wrong sig → sb_invalid_signature", _bsig)

    def _tamper():
        ts = str(int(time.time()))
        empty_hash = hashlib.sha256(b"").hexdigest()
        msg = f"{ts}\nPOST\n/sb/v1/cache/purge\n{empty_hash}".encode()
        sig = hmac.new(sb.secret, msg, hashlib.sha256).hexdigest()
        s, b = raw_request(base, "POST", "/sb/v1/cache/purge",
            {"X-SB-Timestamp": ts, "X-SB-Signature": sig,
             "Content-Type": "application/json"},
            b'{"targets":["wp"]}')
        assert s == 401 and b.get("code") == "sb_invalid_signature"
    t.run("body tampered → sb_invalid_signature", _tamper)

    print("\n[L] Rate-limit ban activates after 5 failures")
    def _ban():
        s, b = raw_request(base, "GET", "/sb/v1/ping",
            {"X-SB-Timestamp": "1700000000", "X-SB-Signature": "de"})
        assert s == 429 and b.get("code") == "sb_rate_limited"
    t.run("6th attempt → sb_rate_limited (429)", _ban)

    return 0 if t.done() else 1


if __name__ == "__main__":
    sys.exit(main())
