# Albert Sans

Font delle pagine pubbliche — accesso, registrazione, recupero e nuova password, cambio
password obbligato — in entrambi gli aspetti, guscio e affiancato. Dentro l'applicazione
il carattere resta quello di sistema.

## Perché il file sta qui e non si carica da Google

Stessa decisione presa per TinyMCE (`pistacchio-lms.md` §4): **niente CDN**. Qui però non è
solo coerenza.

Un `<link>` a `fonts.googleapis.com` fa sì che il browser di chi apre la pagina di accesso
contatti un server di Google **prima ancora di aver fatto accesso**, mandandogli il proprio
indirizzo IP. Nel 2022 il Landgericht di Monaco ha stabilito che incorporare i Google Fonts
in quel modo, senza consenso, viola il GDPR, e da allora la pratica è contestata in tutta
l'Unione. Pistacchio serve formazione finanziata da Regione Lombardia: la pagina di accesso
è il punto meno adatto a una chiamata verso l'esterno.

Con il file qui dentro non parte **nessuna richiesta a terzi**: lo serve lo stesso server
che serve la pagina.

## Che cos'è questo file

`albert-sans-latin.woff2` — **31 KB**, un file solo.

È il font **variabile**: contiene l'intero asse dei pesi da 100 a 900 in un unico file,
quindi grassetto e semigrassetto non costano un secondo scaricamento. È ridotto all'alfabeto
latino più la punteggiatura usata dalle pagine (accentate italiane comprese, €, « », –, …):
l'originale completo pesa 129 KB, questo 31.

Origine: <https://github.com/google/fonts/tree/main/ofl/albertsans>, file
`AlbertSans[wght].ttf`, convertito in woff2 e ridotto con `fonttools`.

**Il corsivo non c'è.** È un secondo file da 57 KB e nelle pagine pubbliche non c'è un solo
testo in corsivo. Se un giorno servisse, si prende `AlbertSans-Italic[wght].ttf` dalla stessa
cartella e si aggiunge una seconda `@font-face` con `font-style: italic`.

## Licenza

SIL Open Font License 1.1 — vedi `albert-sans-OFL.txt`. Consente uso, modifica e
ridistribuzione, anche incorporando il file nel progetto. Il file della licenza va tenuto
accanto al font: è la condizione che la OFL pone.

Copyright 2021 The Albert Sans Project Authors
(<https://github.com/usted/Albert-Sans>).
