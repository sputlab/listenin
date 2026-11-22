/* ListenIn — service worker: uso senza copertura dati.
 *
 *  - Guscio dell'app (pagina, script, stili, cartina, icone): precaricato e servito dalla cache se la rete manca.
 *  - API pubbliche di sola lettura (frequenze, statistiche, cartina…): prima la rete, in mancanza la copia già vista.
 *  - Mai in cache: accesso, account, moderazione, amministrazione, esportazioni e tutte le richieste che modificano dati.
 *  - Le tessere della mappa (OpenStreetMap) non si conservano, come richiedono le regole d'uso di OSM.
 */
const VERSION = '0.7.1';
const CACHE = `listenin-${VERSION}`;
const BASE = new URL('./', self.location).pathname;           // es. /webapp/listenin/
const SHELL_KEY = BASE + '__shell__';
const API_PATH = BASE + 'api/index.php';
const PRIVATE_ROUTES = /^\/(auth|account|admin|moderation|export|opendata\/download)(\/|$)/;

const toUrl = (p) => new URL(p, self.location.origin + BASE).href;

async function precache() {
  const cache = await caches.open(CACHE);
  const res = await fetch(BASE, { credentials: 'same-origin', cache: 'no-store' });
  if (!res.ok) throw new Error('guscio non disponibile');
  await cache.put(SHELL_KEY, res.clone());
  const html = await res.text();
  const seen = new Set();
  const queue = [];
  const add = (u) => { const abs = new URL(u, self.location.origin + BASE).href; if (!seen.has(abs)) { seen.add(abs); queue.push(abs); } };
  for (const m of html.matchAll(/(?:href|src)="([^"]+\.(?:css|js|svg|png|webmanifest)[^"]*)"/g)) add(m[1]);
  add('assets/data/italia-confini.dat');
  // i moduli JavaScript si importano a catena: li si scopre leggendo gli import
  while (queue.length) {
    const url = queue.shift();
    try {
      const r = await fetch(url, { credentials: 'same-origin' });
      if (!r.ok) continue;
      await cache.put(url, r.clone());
      if (/\.js(\?|$)/.test(url)) {
        for (const m of (await r.text()).matchAll(/from\s+'(\.{1,2}\/[^']+\.js[^']*)'/g)) add(new URL(m[1], url).href);
      }
    } catch { /* un file mancante non deve impedire l'installazione */ }
  }
}

self.addEventListener('install', (e) => { e.waitUntil(precache().then(() => self.skipWaiting()).catch(() => self.skipWaiting())); });

self.addEventListener('activate', (e) => {
  e.waitUntil((async () => {
    for (const k of await caches.keys()) if (k.startsWith('listenin-') && k !== CACHE) await caches.delete(k);
    await self.clients.claim();
  })());
});

const withTimeout = (p, ms) => Promise.race([p, new Promise((_, rej) => setTimeout(() => rej(new Error('timeout')), ms))]);

async function networkFirst(request, key = request, ms = 6000) {
  const cache = await caches.open(CACHE);
  try {
    const res = await withTimeout(fetch(request), ms);
    if (res.ok) await cache.put(key, res.clone());
    return res;
  } catch {
    const hit = await cache.match(key);
    if (hit) return hit;
    throw new Error('offline');
  }
}

/** /meta contiene l'utente e il token CSRF: nella cache finisce solo la parte pubblica. */
async function metaFirst(request) {
  const cache = await caches.open(CACHE);
  const key = toUrl('api/index.php?route=/meta');
  try {
    const res = await withTimeout(fetch(request), 6000);
    if (res.ok) {
      const body = await res.clone().json();
      delete body.user; delete body.csrf;
      await cache.put(key, new Response(JSON.stringify(body), { headers: { 'Content-Type': 'application/json; charset=utf-8' } }));
    }
    return res;
  } catch {
    const hit = await cache.match(key);
    if (hit) return hit;
    throw new Error('offline');
  }
}

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  if (url.origin !== self.location.origin) return;

  if (url.pathname === API_PATH) {
    const route = url.searchParams.get('route') || '';
    if (PRIVATE_ROUTES.test(route)) return;                       // nessuna copia di dati personali o amministrativi
    if (route === '/meta') { event.respondWith(metaFirst(req)); return; }
    event.respondWith(networkFirst(req).catch(() => Response.error()));
    return;
  }

  if (req.mode === 'navigate') {
    const p = url.pathname;
    const isShell = p === BASE || p === BASE + 'index.php' || p.startsWith(BASE + 'frequenze');
    event.respondWith(isShell ? networkFirst(req, SHELL_KEY, 4000).catch(() => new Response('<!doctype html><meta charset="utf-8"><title>Offline</title><p>Sei offline e l’app non è ancora stata salvata. Riprova con la connessione.</p>', { status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8' } }))
      : networkFirst(req).catch(() => Response.error()));
    return;
  }

  if (url.pathname.startsWith(BASE + 'assets/') || url.pathname === BASE + 'manifest.webmanifest') {
    event.respondWith((async () => {
      const cache = await caches.open(CACHE);
      const hit = await cache.match(req);
      if (hit) return hit;
      const res = await fetch(req);
      if (res.ok) await cache.put(req, res.clone());
      return res;
    })());
  }
});
