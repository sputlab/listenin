// Applica il tema salvato prima del primo disegno, per evitare il lampo di colore (script classico, non modulo).
(function () {
  try {
    var t = localStorage.getItem('rrf-theme');
    if (t === 'light' || t === 'dark' || t === 'night') document.documentElement.setAttribute('data-theme', t);
  } catch (e) { /* storage non disponibile */ }
})();
