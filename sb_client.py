#!/usr/bin/env python3
"""
Site Bridge — reference Python client.

Reads per-site secrets from a JSON config file. By default, ~/.sb-sites.json,
overridable via SB_SITES_CONFIG environment variable or constructor argument.

Config format:

    {
      "sites": {
        "my-site": {
          "url": "https://example.com",
          "site_bridge_secret": "<64+ char random — same value as SITE_BRIDGE_SECRET in wp-config.php>"
        },
        "staging": {
          "url": "https://staging.example.com",
          "site_bridge_secret": "..."
        }
      }
    }

Usage (Python):
    from sb_client import SiteBridge
    sb = SiteBridge("my-site")
    print(sb.ping())
    page = sb.get_page(123)
    sb.update_page(123, content="<p>New content</p>")

CLI:
    python sb_client.py my-site ping
    python sb_client.py my-site info
    python sb_client.py my-site pages-list --per_page=5
    python sb_client.py my-site page-get 123
    python sb_client.py my-site audit --limit=20
"""

from __future__ import annotations

import argparse
import base64
import hashlib
import hmac
import json
import os
import sys
import time
from pathlib import Path
from typing import Any, Optional
from urllib import request as urlrequest
from urllib.error import HTTPError, URLError


def _default_config_path() -> Path:
    """Resolve config path: $SB_SITES_CONFIG, then ~/.sb-sites.json."""
    env = os.environ.get("SB_SITES_CONFIG")
    if env:
        return Path(env)
    return Path.home() / ".sb-sites.json"


DEFAULT_CONFIG_PATH = _default_config_path()


class SiteBridgeError(Exception):
    """Raised on any non-2xx response or transport failure."""

    def __init__(self, status: int, code: str, message: str, body: Any = None):
        super().__init__(f"HTTP {status} {code}: {message}")
        self.status = status
        self.code = code
        self.message = message
        self.body = body


class SiteBridge:
    """One client = one site."""

    NAMESPACE = "sb/v1"

    def __init__(self, site_key: str, *, config_path: Optional[Path] = None, timeout: float = 30.0):
        config_path = config_path or DEFAULT_CONFIG_PATH
        with open(config_path, encoding="utf-8") as f:
            cfg = json.load(f)
        try:
            site = cfg["sites"][site_key]
        except KeyError as e:
            raise SiteBridgeError(0, "site_not_configured", f"Site '{site_key}' not in {config_path}") from e
        if "site_bridge_secret" not in site:
            raise SiteBridgeError(0, "secret_missing",
                                  f"Site '{site_key}' has no 'site_bridge_secret' in config.")

        self.site_key = site_key
        self.base_url = site["url"].rstrip("/")
        self.secret = site["site_bridge_secret"].encode("utf-8")
        self.timeout = timeout

    # ----- Auth -----

    def _sign(self, method: str, path: str, body: bytes) -> dict[str, str]:
        ts = str(int(time.time()))
        body_hash = hashlib.sha256(body).hexdigest()
        message = f"{ts}\n{method.upper()}\n{path}\n{body_hash}".encode("utf-8")
        sig = hmac.new(self.secret, message, hashlib.sha256).hexdigest()
        return {
            "X-SB-Timestamp": ts,
            "X-SB-Signature": sig,
        }

    # ----- Transport -----

    def request(self, method: str, route: str, *,
                json_body: Optional[dict] = None,
                raw_body: Optional[bytes] = None,
                query: Optional[dict] = None) -> Any:
        # route — e.g. "/pages/1580". Caller passes leading slash.
        route = "/" + route.lstrip("/")
        # Build query string (query is NOT included in the signed path — see auth.php notes)
        query_str = ""
        if query:
            from urllib.parse import urlencode
            query_str = "?" + urlencode(query)
        full_url = f"{self.base_url}/wp-json/{self.NAMESPACE}{route}{query_str}"

        # Body
        if json_body is not None:
            body_bytes = json.dumps(json_body, ensure_ascii=False).encode("utf-8")
            content_type = "application/json; charset=utf-8"
        elif raw_body is not None:
            body_bytes = raw_body
            content_type = "application/octet-stream"
        else:
            body_bytes = b""
            content_type = None

        # Signed path = "/sb/v1{route}" — must match server canonicalization
        signed_path = f"/{self.NAMESPACE}{route}"
        headers = self._sign(method, signed_path, body_bytes)
        if content_type:
            headers["Content-Type"] = content_type
        headers["Accept"] = "application/json"
        headers["User-Agent"] = "site-bridge-client/1.0"

        req = urlrequest.Request(full_url, data=body_bytes if body_bytes else None,
                                 method=method.upper(), headers=headers)
        try:
            with urlrequest.urlopen(req, timeout=self.timeout) as resp:
                status = resp.status
                raw = resp.read()
        except HTTPError as e:
            raw = e.read()
            status = e.code
        except URLError as e:
            raise SiteBridgeError(0, "transport", str(e.reason)) from e

        # Parse response
        try:
            data = json.loads(raw.decode("utf-8"))
        except (UnicodeDecodeError, json.JSONDecodeError):
            data = {"raw_body": raw[:1000]}

        if not (200 <= status < 300):
            code = data.get("code", "http_error") if isinstance(data, dict) else "http_error"
            message = data.get("message", "") if isinstance(data, dict) else ""
            raise SiteBridgeError(status, code, message, data)

        return data

    # ----- High-level methods -----

    def ping(self):
        return self.request("GET", "/ping")

    def info(self):
        return self.request("GET", "/info")

    def audit(self, **filters):
        return self.request("GET", "/audit-log", query=filters)

    def error_log(self, lines: int = 200):
        return self.request("GET", "/error-log", query={"lines": lines})

    # Pages
    def list_pages(self, **filters):
        return self.request("GET", "/pages", query=filters)

    def get_page(self, page_id: int):
        return self.request("GET", f"/pages/{page_id}")

    def update_page(self, page_id: int, **fields):
        return self.request("PATCH", f"/pages/{page_id}", json_body=fields)

    def backup_page(self, page_id: int, notes: str = ""):
        return self.request("POST", f"/pages/{page_id}/backup", json_body={"notes": notes})

    def list_backups(self, page_id: int):
        return self.request("GET", f"/pages/{page_id}/backups")

    def restore_backup(self, page_id: int, backup_id: int):
        return self.request("POST", f"/pages/{page_id}/restore/{backup_id}")

    # Gutenberg blocks (v1.0.2)
    def get_blocks(self, page_id: int):
        return self.request("GET", f"/pages/{page_id}/blocks")

    def put_blocks(self, page_id: int, blocks: list, *, skip_backup: bool = False, notes: str = ""):
        body = {"blocks": blocks, "skip_backup": skip_backup, "notes": notes}
        return self.request("PUT", f"/pages/{page_id}/blocks", json_body=body)

    # Plugins
    def list_plugins(self):
        return self.request("GET", "/plugins")

    def upload_plugin(self, zip_path: str, *, activate: bool = True, overwrite: bool = True):
        with open(zip_path, "rb") as f:
            zip_bytes = f.read()
        return self.request("POST", "/plugins/upload", json_body={
            "filename": os.path.basename(zip_path),
            "zip_base64": base64.b64encode(zip_bytes).decode("ascii"),
            "activate": activate,
            "overwrite": overwrite,
        })

    def activate_plugin(self, slug: str):
        return self.request("POST", f"/plugins/{slug}/activate")

    def deactivate_plugin(self, slug: str):
        return self.request("POST", f"/plugins/{slug}/deactivate")

    def delete_plugin(self, slug: str):
        return self.request("DELETE", f"/plugins/{slug}")

    # Files
    def read_file(self, path: str):
        return self.request("GET", "/files", query={"path": path})

    def write_file(self, path: str, content: str, *, create_dirs: bool = False, mode: Optional[int] = None):
        body = {"content": content, "create_dirs": create_dirs}
        if mode is not None:
            body["mode"] = mode
        return self.request("PUT", "/files", json_body=body, query={"path": path})

    def write_file_bytes(self, path: str, data: bytes, *, create_dirs: bool = False, mode: Optional[int] = None):
        body = {"content_b64": base64.b64encode(data).decode("ascii"), "create_dirs": create_dirs}
        if mode is not None:
            body["mode"] = mode
        return self.request("PUT", "/files", json_body=body, query={"path": path})

    def delete_file(self, path: str):
        return self.request("DELETE", "/files", query={"path": path})

    def list_directory(self, path: str):
        return self.request("GET", "/files/list", query={"path": path})

    # Cache
    def purge_cache(self, *, targets=None, url=None):
        body = {}
        if targets:
            body["targets"] = list(targets)
        if url:
            body["url"] = url
        return self.request("POST", "/cache/purge", json_body=body)

    # Forms
    def list_forms(self):
        return self.request("GET", "/forms")

    def list_submissions(self, **filters):
        return self.request("GET", "/forms/submissions", query=filters)

    def get_submission(self, sub_id: int):
        return self.request("GET", f"/forms/submissions/{sub_id}")


# ----- CLI -----

def _print(obj):
    print(json.dumps(obj, ensure_ascii=False, indent=2))


def main(argv=None):
    p = argparse.ArgumentParser(prog="sb_client")
    p.add_argument("site")
    sub = p.add_subparsers(dest="cmd", required=True)

    sub.add_parser("ping")
    sub.add_parser("info")
    a = sub.add_parser("audit"); a.add_argument("--limit", type=int, default=20)
    e = sub.add_parser("error-log"); e.add_argument("--lines", type=int, default=200)

    pl = sub.add_parser("pages-list")
    pl.add_argument("--per_page", type=int, default=20)
    pl.add_argument("--search", default="")
    pl.add_argument("--status", default="any")
    pl.add_argument("--post_type", default="page")

    pg = sub.add_parser("page-get"); pg.add_argument("id", type=int)
    sub.add_parser("plugins-list")

    args = p.parse_args(argv)
    sb = SiteBridge(args.site)

    if args.cmd == "ping":
        _print(sb.ping())
    elif args.cmd == "info":
        _print(sb.info())
    elif args.cmd == "audit":
        _print(sb.audit(limit=args.limit))
    elif args.cmd == "error-log":
        _print(sb.error_log(lines=args.lines))
    elif args.cmd == "pages-list":
        _print(sb.list_pages(per_page=args.per_page, search=args.search,
                             status=args.status, post_type=args.post_type))
    elif args.cmd == "page-get":
        _print(sb.get_page(args.id))
    elif args.cmd == "plugins-list":
        _print(sb.list_plugins())


if __name__ == "__main__":
    try:
        main()
    except SiteBridgeError as e:
        print(f"ERROR: {e}", file=sys.stderr)
        sys.exit(1)
