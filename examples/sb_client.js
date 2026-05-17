#!/usr/bin/env node
/**
 * Site Bridge — Node.js reference client.
 *
 * Pure stdlib (uses node:crypto and the built-in fetch global from Node 18+).
 * No npm dependencies.
 *
 * Usage:
 *
 *   const sb = new SiteBridge({
 *     baseUrl: 'https://example.com',
 *     secret:  process.env.SB_SECRET,
 *   });
 *
 *   const ping = await sb.request('GET', '/ping');
 *   const page = await sb.request('GET', '/pages/123');
 *   await sb.request('PATCH', '/pages/123', { content: '<p>Hello</p>' });
 */

const crypto = require('node:crypto');

class SiteBridge {
  constructor({ baseUrl, secret, namespace = 'sb/v1', timeoutMs = 30000 }) {
    if (!baseUrl) throw new Error('baseUrl is required');
    if (!secret) throw new Error('secret is required');
    this.baseUrl = baseUrl.replace(/\/+$/, '');
    this.secret = secret;
    this.namespace = namespace;
    this.timeoutMs = timeoutMs;
  }

  _sign(method, path, bodyBytes) {
    const ts = String(Math.floor(Date.now() / 1000));
    const bodyHash = crypto.createHash('sha256').update(bodyBytes).digest('hex');
    const message = `${ts}\n${method.toUpperCase()}\n${path}\n${bodyHash}`;
    const signature = crypto.createHmac('sha256', this.secret).update(message).digest('hex');
    return { 'X-SB-Timestamp': ts, 'X-SB-Signature': signature };
  }

  /**
   * @param {string} method  HTTP method
   * @param {string} route   e.g. '/pages/123'
   * @param {object} [body]  JSON body (will be stringified). For GET, omit.
   * @param {object} [query] Query parameters (NOT included in signature)
   */
  async request(method, route, body = null, query = null) {
    route = '/' + route.replace(/^\/+/, '');
    const signedPath = `/${this.namespace}${route}`;

    let queryStr = '';
    if (query && Object.keys(query).length > 0) {
      const params = new URLSearchParams();
      for (const [k, v] of Object.entries(query)) {
        if (v !== undefined && v !== null) params.set(k, String(v));
      }
      queryStr = '?' + params.toString();
    }

    const url = `${this.baseUrl}/wp-json/${this.namespace}${route}${queryStr}`;

    let bodyBytes = Buffer.alloc(0);
    const headers = { Accept: 'application/json', 'User-Agent': 'site-bridge-client-node/1.0' };
    if (body !== null && body !== undefined) {
      const json = JSON.stringify(body);
      bodyBytes = Buffer.from(json, 'utf-8');
      headers['Content-Type'] = 'application/json; charset=utf-8';
    }
    Object.assign(headers, this._sign(method, signedPath, bodyBytes));

    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), this.timeoutMs);
    try {
      const res = await fetch(url, {
        method: method.toUpperCase(),
        headers,
        body: bodyBytes.length ? bodyBytes : undefined,
        signal: controller.signal,
      });
      const text = await res.text();
      let parsed;
      try { parsed = JSON.parse(text); } catch { parsed = { raw: text }; }
      if (!res.ok) {
        const err = new Error(`HTTP ${res.status}: ${parsed?.message || res.statusText}`);
        err.status = res.status;
        err.code = parsed?.code;
        err.body = parsed;
        throw err;
      }
      return parsed;
    } finally {
      clearTimeout(timer);
    }
  }

  // Convenience helpers
  ping()                                    { return this.request('GET', '/ping'); }
  info()                                    { return this.request('GET', '/info'); }
  getPage(id)                               { return this.request('GET', `/pages/${id}`); }
  updatePage(id, fields)                    { return this.request('PATCH', `/pages/${id}`, fields); }
  getBlocks(id)                             { return this.request('GET', `/pages/${id}/blocks`); }
  putBlocks(id, blocks, opts = {})          { return this.request('PUT', `/pages/${id}/blocks`, { blocks, ...opts }); }
  listBackups(id)                           { return this.request('GET', `/pages/${id}/backups`); }
  restoreBackup(id, backupId)               { return this.request('POST', `/pages/${id}/restore/${backupId}`); }
  purgeCache(body = {})                     { return this.request('POST', '/cache/purge', body); }
  listPlugins()                             { return this.request('GET', '/plugins'); }
}

module.exports = { SiteBridge };

// CLI mode
if (require.main === module) {
  (async () => {
    const baseUrl = process.env.SB_URL;
    const secret = process.env.SB_SECRET;
    if (!baseUrl || !secret) {
      console.error('Set SB_URL and SB_SECRET env vars to use the CLI.');
      console.error('Example: SB_URL=https://example.com SB_SECRET=xxx node sb_client.js ping');
      process.exit(2);
    }
    const sb = new SiteBridge({ baseUrl, secret });
    const cmd = process.argv[2] || 'ping';
    try {
      let result;
      if (cmd === 'ping')      result = await sb.ping();
      else if (cmd === 'info') result = await sb.info();
      else if (cmd === 'page-get') result = await sb.getPage(parseInt(process.argv[3]));
      else if (cmd === 'plugins') result = await sb.listPlugins();
      else { console.error('Unknown command. Try: ping, info, page-get <id>, plugins'); process.exit(2); }
      console.log(JSON.stringify(result, null, 2));
    } catch (e) {
      console.error(`ERROR: ${e.message}`, e.body || '');
      process.exit(1);
    }
  })();
}
