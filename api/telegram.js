// AI Screens — Telegram Notifier (Vercel Serverless Function)
// Endpoint: POST /api/telegram

const BOT_TOKEN = process.env.BOT_TOKEN || '8772249847:AAHaODRYrbTYb_y5T-4xDk79pOd_DKUdjq8';
const CHAT_ID   = process.env.CHAT_ID   || '-1003980462985';
const RATE_LIMIT_SECONDS = 30;

// In-memory rate limit (per warm instance — good enough)
const rateCache = new Map();

function realIP(req) {
  const headers = req.headers || {};
  const candidates = [
    headers['cf-connecting-ip'],
    headers['x-vercel-forwarded-for'],
    headers['x-real-ip'],
    headers['x-forwarded-for'],
  ];
  for (const h of candidates) {
    if (!h) continue;
    const ip = String(h).split(',')[0].trim();
    if (ip) return ip;
  }
  return (req.socket && req.socket.remoteAddress) || '0.0.0.0';
}

function esc(s) {
  return String(s ?? '').replace(/[<>&]/g, c => ({ '<': '&lt;', '>': '&gt;', '&': '&amp;' }[c]));
}

function countryFlag(cc) {
  if (!cc || cc.length !== 2) return '🌍';
  const A = 0x1F1E6;
  return String.fromCodePoint(A + cc.charCodeAt(0) - 65) + String.fromCodePoint(A + cc.charCodeAt(1) - 65);
}

async function httpGetJson(url, timeoutMs = 5000) {
  const ctrl = new AbortController();
  const t = setTimeout(() => ctrl.abort(), timeoutMs);
  try {
    const r = await fetch(url, {
      signal: ctrl.signal,
      headers: { 'User-Agent': 'Mozilla/5.0 (AIScreens/1.0)', 'Accept': 'application/json' }
    });
    if (!r.ok) return null;
    return await r.json();
  } catch { return null; }
  finally { clearTimeout(t); }
}

async function fetchGeo(ip) {
  if (!ip || ip === '0.0.0.0' || ip === '127.0.0.1' || ip.startsWith('192.168.') || ip.startsWith('10.') || ip === '::1') return {};

  // Provider 1: ip-api.com
  const d1 = await httpGetJson(`http://ip-api.com/json/${ip}?fields=status,country,countryCode,region,regionName,city,zip,lat,lon,timezone,isp,org,as,currency`);
  if (d1 && d1.status === 'success') {
    return {
      country_name: d1.country, country_code: d1.countryCode,
      region: d1.regionName || d1.region, city: d1.city, postal: d1.zip,
      latitude: d1.lat, longitude: d1.lon, timezone: d1.timezone,
      org: d1.isp || d1.org, asn: d1.as, currency: d1.currency,
    };
  }

  // Provider 2: ipwho.is
  const d2 = await httpGetJson(`https://ipwho.is/${ip}`);
  if (d2 && d2.success) {
    return {
      country_name: d2.country, country_code: d2.country_code,
      region: d2.region, city: d2.city, postal: d2.postal,
      latitude: d2.latitude, longitude: d2.longitude,
      timezone: d2.timezone && d2.timezone.id,
      org: d2.connection && d2.connection.isp,
      asn: d2.connection && d2.connection.asn ? 'AS' + d2.connection.asn : null,
      currency: d2.currency && d2.currency.code,
    };
  }

  // Provider 3: ipapi.co
  const d3 = await httpGetJson(`https://ipapi.co/${ip}/json/`);
  if (d3 && !d3.error) return d3;

  return {};
}

function rateLimited(ip, event) {
  const key = ip + '|' + event;
  const now = Date.now();
  const prev = rateCache.get(key);
  if (prev && (now - prev) < RATE_LIMIT_SECONDS * 1000) return true;
  rateCache.set(key, now);
  if (rateCache.size > 5000) {
    for (const [k, t] of rateCache) if (now - t > 600000) rateCache.delete(k);
  }
  return false;
}

async function readBody(req) {
  if (req.body) return typeof req.body === 'string' ? JSON.parse(req.body) : req.body;
  return new Promise((resolve) => {
    let data = '';
    req.on('data', c => data += c);
    req.on('end', () => { try { resolve(JSON.parse(data || '{}')); } catch { resolve({}); } });
    req.on('error', () => resolve({}));
  });
}

export default async function handler(req, res) {
  res.setHeader('Access-Control-Allow-Origin', '*');
  res.setHeader('Access-Control-Allow-Methods', 'POST, OPTIONS');
  res.setHeader('Access-Control-Allow-Headers', 'Content-Type');

  if (req.method === 'OPTIONS') return res.status(204).end();
  if (req.method !== 'POST') return res.status(405).json({ ok: false, error: 'Method not allowed' });

  if (BOT_TOKEN.startsWith('YOUR_') || CHAT_ID.startsWith('YOUR_')) {
    return res.status(500).json({ ok: false, error: 'Telegram credentials not configured' });
  }

  const body = await readBody(req);
  const event = String(body.event || 'Page Visit').slice(0, 120);
  const client = (body.client && typeof body.client === 'object') ? body.client : {};

  const ip = realIP(req);
  if (rateLimited(ip, event)) return res.status(200).json({ ok: true, skipped: 'rate_limited' });

  const geo = await fetchGeo(ip);
  const ua  = req.headers['user-agent']      || '';
  const ref = req.headers['referer']         || '';
  const acceptLang = req.headers['accept-language'] || '';

  const flag = countryFlag(geo.country_code || '');
  const evIcon = /download/i.test(event) ? '⬇️' : '👀';

  const L = [];
  L.push(`${evIcon} <b>AI Screens — ${esc(event)}</b> ${flag}`);
  L.push(`🕐 <b>UTC:</b> ${new Date().toISOString().replace('T',' ').slice(0,19)}`);
  L.push('');
  L.push(`🌐 <b>IP:</b> <code>${esc(ip)}</code>`);
  if (geo.country_name)  L.push(`🏳️ <b>Country:</b> ${esc(geo.country_name)} (${esc(geo.country_code || '?')})`);
  if (geo.city || geo.region) L.push(`🏙️ <b>City / Region:</b> ${esc(geo.city || '?')} / ${esc(geo.region || '?')}`);
  if (geo.postal)        L.push(`📮 <b>Postal:</b> ${esc(geo.postal)}`);
  if (geo.latitude != null) L.push(`📍 <b>Coords:</b> ${esc(geo.latitude)}, ${esc(geo.longitude)} (<a href="https://maps.google.com/?q=${geo.latitude},${geo.longitude}">map</a>)`);
  if (geo.org)           L.push(`🛰️ <b>ISP:</b> ${esc(geo.org)}`);
  if (geo.asn)           L.push(`🔌 <b>ASN:</b> ${esc(geo.asn)}`);
  if (geo.timezone)      L.push(`⏰ <b>IP Timezone:</b> ${esc(geo.timezone)}`);
  if (geo.currency)      L.push(`💱 <b>Currency:</b> ${esc(geo.currency)}`);
  L.push('');
  L.push(`🔗 <b>Visited URL:</b> ${esc(client.url || '')}`);
  L.push(`↩️ <b>Referrer:</b> ${esc(client.referrer || ref || '(direct)')}`);
  if (client.utm) L.push(`🎯 <b>UTM:</b> <code>${esc(client.utm)}</code>`);
  L.push('');
  if (client.platform)   L.push(`💻 <b>Platform:</b> ${esc(client.platform)}${client.vendor ? ' · ' + esc(client.vendor) : ''}`);
  if (client.screen)     L.push(`🖥️ <b>Screen:</b> ${esc(client.screen)}`);
  if (client.viewport)   L.push(`📐 <b>Viewport:</b> ${esc(client.viewport)} (DPR ${esc(client.devicePixelRatio ?? '?')})`);
  if (client.gpu)        L.push(`🎨 <b>GPU:</b> ${esc(client.gpu)}`);
  if (client.language)   L.push(`🌍 <b>Language:</b> ${esc(client.language)}${client.languages ? ' (' + esc(client.languages) + ')' : ''}`);
  if (client.timezone)   L.push(`🕓 <b>Browser TZ:</b> ${esc(client.timezone)} (offset ${esc(client.timezoneOffset ?? '?')})`);
  if (client.localTime)  L.push(`📅 <b>Local Time:</b> ${esc(client.localTime)}`);
  if (client.hardwareConcurrency != null) L.push(`⚙️ <b>CPU cores:</b> ${esc(client.hardwareConcurrency)} · <b>RAM:</b> ${esc(client.deviceMemory || 'n/a')}GB`);
  if (client.connection) L.push(`📡 <b>Connection:</b> ${esc(client.connection)}`);
  if (client.battery)    L.push(`🔋 <b>Battery:</b> ${esc(client.battery)}`);
  if (client.storage)    L.push(`💾 <b>Storage:</b> ${esc(client.storage)}`);
  if (client.maxTouchPoints != null) L.push(`👆 <b>Touch points:</b> ${esc(client.maxTouchPoints)}`);
  if (client.cookieEnabled != null) L.push(`🍪 <b>Cookies:</b> ${client.cookieEnabled ? 'on' : 'off'} · <b>DNT:</b> ${esc(client.doNotTrack || 'no')}`);
  L.push('');
  if (acceptLang) L.push(`🗣️ <b>Accept-Language:</b> ${esc(acceptLang)}`);
  L.push(`🧬 <b>User Agent:</b>`);
  L.push(`<code>${esc(client.userAgent || ua)}</code>`);

  let text = L.join('\n');
  if (text.length > 4000) text = text.slice(0, 3990) + '\n…(truncated)';

  try {
    const tgRes = await fetch(`https://api.telegram.org/bot${BOT_TOKEN}/sendMessage`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        chat_id: CHAT_ID,
        text,
        parse_mode: 'HTML',
        disable_web_page_preview: true,
      }),
    });
    const data = await tgRes.json().catch(() => ({}));
    return res.status(200).json({ ok: !!data.ok, tg: data });
  } catch (e) {
    return res.status(500).json({ ok: false, error: String(e) });
  }
}
