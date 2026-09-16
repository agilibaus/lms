# TinyMCE incluso nel progetto

**Versione:** 7.9.3 — **licenza:** GPL-2.0-or-later (vedi `license.md` e `notices.txt`)
**Origine:** pacchetto ufficiale `tinymce` su npm (`npm pack tinymce@7`)

Qui non c'è il pacchetto completo (11 MB) ma solo i file effettivamente caricati
dall'editor delle lezioni (1,5 MB):

- `tinymce.min.js` — il nucleo
- `models/dom`, `themes/silver`, `icons/default` — modello, tema e icone predefiniti
- `skins/ui/oxide`, `skins/content/default` — aspetto dell'interfaccia e del contenuto
- `plugins/` — solo quelli richiamati in `app/Views/lessons/form.php`:
  advlist, autolink, autoresize, charmap, code, fullscreen, image, link, lists,
  media, searchreplace, table, wordcount
- `langs/it.js` — interfaccia in italiano

## Aggiornare la versione

```bash
npm pack tinymce@7
tar xzf tinymce-*.tgz
```

poi ricopiare gli stessi file da `package/` in questa cartella. Se aggiungi un
plugin alla configurazione dell'editor, ricordati di copiarne anche la cartella:
un plugin dichiarato ma assente fa fallire il caricamento dell'editor.

La traduzione italiana non sta nel pacchetto npm di TinyMCE: viene dal pacchetto
`tinymce-i18n`, cartella `langs7` (quella per la serie 7 — `langs` contiene la
serie 8 e con TinyMCE 7 mostrerebbe voci non tradotte).

```bash
npm pack tinymce-i18n
tar xzf tinymce-i18n-*.tgz
cp package/langs7/it.js langs/it.js
```

Licenza della traduzione: MIT per gli adattamenti, stessi termini di TinyMCE per
i file originali (`langs/LICENSE.md` e `langs/NOTICE.md`).

## Nota sulla licenza

TinyMCE community è distribuito con licenza **GPL-2.0-or-later**, mentre
`composer.json` dichiara il progetto come `proprietary`. Finché la piattaforma
viene solo installata e usata (anche per conto terzi) la GPL non impone nulla,
perché non c'è distribuzione del software. Se invece Pistacchio LMS venisse
ceduto o venduto a terzi, le due licenze andrebbero conciliate: o il progetto
adotta la GPL, o serve una licenza commerciale di TinyMCE, o si sostituisce
l'editor con uno a licenza permissiva.
