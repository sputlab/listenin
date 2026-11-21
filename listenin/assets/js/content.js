// Testi legali: caricati dall'API e inseriti dopo una sanificazione ad allowlist.
import { get, BASE } from './api.js?v=0.6.0';
import { h, openModal, toast } from './dom.js?v=0.6.0';

const ALLOWED = new Set(['P', 'H2', 'H3', 'UL', 'OL', 'LI', 'STRONG', 'EM', 'A', 'BR']);

function sanitize(html) {
  const doc = new DOMParser().parseFromString(html, 'text/html');
  const walk = (node) => {
    const out = document.createDocumentFragment();
    for (const n of node.childNodes) {
      if (n.nodeType === Node.TEXT_NODE) out.append(n.textContent);
      else if (n.nodeType === Node.ELEMENT_NODE) {
        if (!ALLOWED.has(n.tagName)) { out.append(walk(n)); continue; }
        const el = document.createElement(n.tagName.toLowerCase());
        if (n.tagName === 'A') {
          const href = n.getAttribute('href') || '';
          if (/^(https:|\/|#)/.test(href)) el.setAttribute('href', href);
          el.setAttribute('rel', 'noopener');
        }
        el.append(walk(n));
        out.append(el);
      }
    }
    return out;
  };
  return walk(doc.body);
}

const cache = new Map();

export async function openDoc(doc) {
  try {
    if (!cache.has(doc)) cache.set(doc, await get('/content/' + doc));
    const c = cache.get(doc);
    const body = h('div', { class: 'prose' });
    if (doc === 'disclaimer') body.append(h('p', { class: 'doc-meta' }, 'Versione ' + c.version));
    body.append(sanitize(c.html));
    openModal(c.title, body);
  } catch (e) {
    toast(e.message, 'error');
  }
}

/** Collegamento da usare dentro i moduli: apre la pagina in una nuova scheda per non perdere i dati inseriti. */
export function docLink(doc, text) {
  return h('a', { href: BASE + 'pagina.php?doc=' + doc, target: '_blank', rel: 'noopener' }, text);
}
