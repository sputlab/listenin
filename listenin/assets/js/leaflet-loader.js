// Carica Leaflet (servito dal sito stesso, nessun CDN) solo quando serve una mappa.
import { BASE } from './api.js?v=0.7.0';

let promise = null;
export function loadLeaflet() {
  promise ??= new Promise((resolve, reject) => {
    if (window.L) { resolve(window.L); return; }
    const css = document.createElement('link');
    css.rel = 'stylesheet';
    css.href = BASE + 'assets/vendor/leaflet/leaflet.css';
    document.head.append(css);
    const js = document.createElement('script');
    js.src = BASE + 'assets/vendor/leaflet/leaflet.js';
    js.onload = () => { window.L.Icon.Default.imagePath = BASE + 'assets/vendor/leaflet/images/'; resolve(window.L); };
    js.onerror = () => { promise = null; reject(new Error('Mappa non disponibile.')); };
    document.head.append(js);
  });
  return promise;
}

/** Raggio di copertura stimato in km (orizzonte radio): 4,12·(√h_tx + √h_rx), con ricevente a 10 m. Null se manca la quota. */
export function estimateCoverageKm(altitudeM) {
  return Number.isFinite(altitudeM) && altitudeM > 0 ? Math.round(4.12 * (Math.sqrt(altitudeM) + Math.sqrt(10))) : null;
}

/** Crea una mappa Leaflet con le tessere configurate (OpenStreetMap per impostazione predefinita). */
export async function createMap(el, tiles, view, zoom) {
  const L = await loadLeaflet();
  const map = L.map(el, { scrollWheelZoom: false, zoomControl: true }).setView(view, zoom);
  L.tileLayer(tiles.tile_url, { maxZoom: 18, attribution: tiles.attribution.includes('OpenStreetMap') ? '© <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">contributori di OpenStreetMap</a>' : tiles.attribution }).addTo(map);
  return { L, map };
}
