#!/usr/bin/env python3
"""
Aggiunge ?v=<versione> a tutti gli import dei moduli JavaScript (assets/js), con la versione di app/VERSION.

Perché: il browser mette in cache i moduli per URL. Se dopo un aggiornamento l'URL non cambia, può continuare a
usare i file vecchi (es. un pannello senza le novità). Con la versione nell'URL ogni rilascio forza il ricaricamento.
Da eseguire a ogni nuova versione, prima di creare il pacchetto:  python3 tools/stamp_version.py
"""
import re, pathlib

root = pathlib.Path(__file__).resolve().parent.parent / 'listenin'
version = (root / 'app' / 'VERSION').read_text().strip()
pattern = re.compile(r"(from\s+')(\.{1,2}/[^']+?\.js)(\?v=[^']*)?(')")
changed = 0
for f in sorted((root / 'assets' / 'js').rglob('*.js')):
    src = f.read_text(encoding='utf-8')
    out = pattern.sub(lambda m: f"{m.group(1)}{m.group(2)}?v={version}{m.group(4)}", src)
    if out != src:
        f.write_text(out, encoding='utf-8')
        changed += 1
sw = root / 'sw.js'
if sw.exists():
    src = sw.read_text(encoding='utf-8')
    out = re.sub(r"const VERSION = '[^']*';", f"const VERSION = '{version}';", src)
    if out != src:
        sw.write_text(out, encoding='utf-8'); changed += 1
print(f'versione {version}: aggiornati {changed} file')
