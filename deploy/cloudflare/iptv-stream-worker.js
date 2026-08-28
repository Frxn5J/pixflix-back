/**
 * Pixflix — IPTV stream proxy for Cloudflare Workers
 * =====================================================
 *
 * Streams RAW media bytes (HLS segments, MP4, live MPEG-TS) so they never
 * consume the origin server's bandwidth (e.g. an Oracle free-tier VM).
 * Playlists (.m3u8) are NOT handled here: the Laravel backend rewrites and
 * re-signs them; every media URL inside a rewritten manifest already points
 * to this Worker with a valid signature.
 *
 * Contract with the backend (App\Services\Streaming\StreamSigner):
 *   URL params:  target, expires, signature[, ua, referer]
 *   Signature:   HMAC-SHA256 hex of "stream|{expires}|{target}"
 *                with secret PIXFLIX_STREAM_SECRET (wrangler secret).
 *
 * Deploy:
 *   1. npx wrangler deploy            (from this folder, after wrangler.toml)
 *   2. npx wrangler secret put PIXFLIX_STREAM_SECRET
 *   3. In the Laravel .env set:
 *        PIXFLIX_STREAM_PROXY_BASE_URL=https://pixflix-stream.<account>.workers.dev
 *        PIXFLIX_STREAM_PROXY_SECRET=<same secret as above>
 *
 * Free plan: 100k requests/day (~10-15 concurrent HLS viewers).
 * Workers Paid ($5/mo): 10M requests/month (~30-40 concurrent viewers).
 */

const MAX_EXPIRY_WINDOW_SECONDS = 26 * 3600; // VOD URLs live 12h; allow retries
const PROXY_TIMEOUT_MS = 30000;

const CORS_HEADERS = {
  'Access-Control-Allow-Origin': '*',
  'Access-Control-Allow-Headers': '*',
  'Access-Control-Expose-Headers': 'Content-Length, Content-Range, Accept-Ranges',
};

const PASSTHROUGH_HEADERS = [
  'Content-Type',
  'Content-Length',
  'Content-Range',
  'Accept-Ranges',
  'Last-Modified',
  'ETag',
];

export default {
  async fetch(request, env) {
    if (request.method === 'OPTIONS') {
      return new Response(null, {
        status: 204,
        headers: { ...CORS_HEADERS, 'Access-Control-Max-Age': '86400' },
      });
    }

    if (request.method !== 'GET' && request.method !== 'HEAD') {
      return json({ error: { code: 'method_not_allowed' } }, 405);
    }

    if (!env || !env.PIXFLIX_STREAM_SECRET) {
      return json({ error: { code: 'worker_misconfigured' } }, 500);
    }

    const url = new URL(request.url);
    const target = url.searchParams.get('target') || '';
    const expires = Number(url.searchParams.get('expires') || 0);
    const signature = url.searchParams.get('signature') || '';
    const now = Math.floor(Date.now() / 1000);

    if (!isHttpUrl(target)) {
      return json({ error: { code: 'invalid_stream_url' } }, 403);
    }

    if (!expires || expires <= now || expires > now + MAX_EXPIRY_WINDOW_SECONDS) {
      return json({ error: { code: 'invalid_stream_url' } }, 403);
    }

    const expected = await hmac(`stream|${expires}|${target}`, env.PIXFLIX_STREAM_SECRET);
    if (!timingSafeEqual(expected, signature)) {
      return json({ error: { code: 'invalid_stream_url' } }, 403);
    }

    const upstreamHeaders = new Headers({
      'User-Agent': url.searchParams.get('ua') || 'Pixflix/1.0 IPTV player',
      Accept: '*/*',
    });
    const referer = url.searchParams.get('referer');
    if (referer) upstreamHeaders.set('Referer', referer);
    for (const header of ['Range', 'If-Range']) {
      const value = request.headers.get(header);
      if (value) upstreamHeaders.set(header, value);
    }

    let upstream;
    try {
      upstream = await fetch(target, {
        headers: upstreamHeaders,
        redirect: 'follow',
        signal: AbortSignal.timeout(PROXY_TIMEOUT_MS),
      });
    } catch (error) {
      return json({ error: { code: 'stream_unavailable' } }, 502);
    }

    const headers = new Headers(CORS_HEADERS);
    headers.set('Cache-Control', 'no-store, no-cache, must-revalidate');
    for (const name of PASSTHROUGH_HEADERS) {
      const value = upstream.headers.get(name);
      if (value) headers.set(name, value);
    }

    return new Response(request.method === 'HEAD' ? null : upstream.body, {
      status: upstream.status,
      headers,
    });
  },
};

function isHttpUrl(value) {
  try {
    const parsed = new URL(value);
    return parsed.protocol === 'http:' || parsed.protocol === 'https:';
  } catch {
    return false;
  }
}

async function hmac(payload, secret) {
  const key = await crypto.subtle.importKey(
    'raw',
    new TextEncoder().encode(secret),
    { name: 'HMAC', hash: 'SHA-256' },
    false,
    ['sign'],
  );
  const mac = await crypto.subtle.sign('HMAC', key, new TextEncoder().encode(payload));
  return [...new Uint8Array(mac)].map((b) => b.toString(16).padStart(2, '0')).join('');
}

function timingSafeEqual(a, b) {
  if (typeof a !== 'string' || typeof b !== 'string' || a.length !== b.length) {
    return false;
  }
  let diff = 0;
  for (let i = 0; i < a.length; i++) {
    diff |= a.charCodeAt(i) ^ b.charCodeAt(i);
  }
  return diff === 0;
}

function json(body, status) {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json', ...CORS_HEADERS },
  });
}
