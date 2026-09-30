# player.js

`player.min.js` è **player.js di Embedly**, versione **0.1.0**, licenza BSD a tre clausole
(il testo completo è in `LICENSE`). Copiato senza modifiche da
`dist/player-0.1.0.min.js` del repository ufficiale
<https://github.com/embedly/player.js>, come si è fatto con TinyMCE: la piattaforma
non chiama nessun file esterno, e quello che gira sul nostro dominio l'abbiamo visto.

## A cosa serve

È la metà *nostra* del dialogo con l'iframe di Bunny Stream: `getCurrentTime()`,
`setCurrentTime()` e gli eventi `timeupdate`, `pause`, `ended`. Bunny implementa lo
stesso protocollo (messaggi `postMessage` in JSON) dentro il proprio iframe.

Il pezzo che scriviamo noi è un file diverso, `public/assets/js/lesson-tracking.js`:
ascolta quegli eventi, salva la posizione e manda i tempi guardati. Non confondere i due.

## La versione, e perché è una cosa da sapere

Bunny nella sua documentazione indica `assets.mediadelivery.net/playerjs/playerjs-latest.min.js`,
che al 30/09/2026 serve la **0.0.11**. Qui c'è la **0.1.0**, presa dal repository ufficiale
perché `assets.mediadelivery.net` è bloccato dal proxy del container e un file che non si
può leggere non si mette in un progetto.

Il protocollo `postMessage` è lo stesso e non è cambiato fra le due versioni, ma **questo
dal container non si può verificare contro un iframe vero di Bunny**. Se con un video vero
l'API risultasse muta — cioè se i pulsanti "Riprendi" non comparissero mai e in tabella non
finisse niente — la prima cosa da provare è sostituire questo file con quello servito da
Bunny:

```
curl -o public/assets/vendor/playerjs/player.min.js \
     https://assets.mediadelivery.net/playerjs/playerjs-latest.min.js
```

Non serve toccare altro: `lesson-tracking.js` usa solo le funzioni presenti in entrambe.
