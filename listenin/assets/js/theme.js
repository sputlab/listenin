// Tema dell'interfaccia: automatico (segue il sistema), chiaro, scuro e "notte" (rosso ad alto contrasto per la visione notturna).
const THEMES = [['auto', 'Automatico'], ['light', 'Chiaro'], ['dark', 'Scuro'], ['night', 'Notte']];
const KEY = 'rrf-theme';

export function currentTheme() {
  try { const t = localStorage.getItem(KEY); if (THEMES.some(([k]) => k === t)) return t; } catch { /* storage non disponibile */ }
  return 'auto';
}

export function applyTheme(t) {
  if (t === 'auto') document.documentElement.removeAttribute('data-theme'); else document.documentElement.setAttribute('data-theme', t);
  try { if (t === 'auto') localStorage.removeItem(KEY); else localStorage.setItem(KEY, t); } catch { /* storage non disponibile */ }
}

export const themeLabel = (t) => THEMES.find(([k]) => k === t)?.[1] ?? 'Automatico';

/** Passa al tema successivo e restituisce il nuovo valore. */
export function cycleTheme() {
  const i = THEMES.findIndex(([k]) => k === currentTheme());
  const next = THEMES[(i + 1) % THEMES.length][0];
  applyTheme(next);
  return next;
}
