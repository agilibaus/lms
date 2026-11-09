# Test di carico di Pistacchio LMS

Il test simula studenti che entrano, guardano i loro corsi, aprono il corso, due
lezioni e il questionario, l'Agenda ed «Esplora corsi», ed escono, con una pausa
da 5 a 15 secondi fra una pagina e l'altra, come una persona vera. Nella prima
lezione con un video di Bunny lo «guardano» da 2 a 5 minuti.

Le soglie vengono dal conto del 06/11: 35 processi PHP dedicati, 150 persone
collegate, 30 pagine al secondo nei momenti di punta.

| Soglia | Cosa vuol dire |
|---|---|
| il 95% delle pagine entro **1,15 secondi** | il limite: oltre, i processi non bastano |
| il 95% delle pagine entro **390 ms** | l'obiettivo, con un margine di tre volte |

Misura solo le richieste a Pistacchio, cioè il lavoro di PHP. Fogli di stile,
caratteri e immagini li serve il server web senza PHP, e i video li serve Bunny.
Chi guarda un video di Bunny, però, manda a Pistacchio la fruizione: ogni 60
secondi mentre il video va. Lo script la manda allo stesso modo, così il test
misura anche quella (nel caso peggiore, tutti e 150 davanti a un video, circa
2,5 richieste al secondo in più).

## Prima della prova, sul server

1. **Copia del database** dal pannello di SupportHost (o con `mysqldump`). Dopo la
   prova si rimette questa copia, e tutto torna com'era.
2. **OPcache attivo**: in Impostazioni › Informazioni su Pistacchio LMS deve dire
   «OPcache attivo».
3. **Posta su «log»** in Impostazioni › Server di posta elettronica, e **pagamenti
   spenti** in Impostazioni › Pagamenti.
4. **Il corso pubblicato**, con qualche modulo, lezioni e un questionario. Il suo
   numero è nell'indirizzo della sua pagina: `/courses/12` → 12.
5. **Gli studenti di prova**, dal Terminale di cPanel, nella cartella di Pistacchio:

   ```
   php bin/carico semina --corso=12 --password=UnaPasswordLunga2026
   ```

   Crea `carico-001@carico.invalid` … `carico-500@carico.invalid`, tutti con quella
   password (almeno 12 caratteri, solo lettere e numeri, così il prompt dei comandi
   non la confonde), già oltre il primo accesso, e li iscrive al corso.
   A metà di loro segna fatta la prima lezione. Nessuna email in coda. Con più corsi:
   `--corso=12 --corso=15`. `php bin/carico conta` dice quanti sono.

## Sul tuo PC

**Installare k6**, una volta sola, da un prompt dei comandi:

```
winget install k6 --source winget
```

Poi, sempre dal prompt, nella cartella di Pistacchio (`C:\laragon\www\lms`).

**1. La prova piccola** (5 utenti per 3 minuti, per vedere che tutto funziona):

```
k6 run -e BASE=https://il-tuo-dominio -e PASSWORD=UnaPasswordLunga2026 -e PICCOLO=1 tests/carico/studente.js
```

Alla fine k6 stampa il riepilogo. Le righe che contano:

- **`checks`**: deve essere vicino al 100%. Se «accesso riuscito» fallisce, la
  password o il dominio non sono quelli giusti;
- **`http_req_duration{pagina:si}`**: le due soglie, con ✓ o ✗ accanto;
- **`http_req_failed`**: le pagine andate in errore, deve stare sotto l'1%.

Anche la prova piccola arriva da casa tua: va detta a SupportHost come la grande,
con il tuo indirizzo IP.

**2. La prova vera** (150 utenti, circa 27 minuti: 5 di salita, 20 a 150 collegati,
2 di discesa). Un'ora prima comunica a SupportHost l'indirizzo IP (cerca «qual è il
mio IP» nel browser, dallo stesso PC) e non riavviare il router. Poi:

```
set K6_WEB_DASHBOARD=true
set K6_WEB_DASHBOARD_EXPORT=risultato-carico.html
k6 run -e BASE=https://il-tuo-dominio -e PASSWORD=UnaPasswordLunga2026 tests/carico/studente.js
```

Le due righe `set` sono facoltative: aprono i grafici della prova nel browser
(l'indirizzo lo stampa k6 all'inizio, di solito `http://localhost:5665`) e alla
fine li salvano in `risultato-carico.html`. Sono gratuite e non passano da Grafana.

**Grafana Cloud, se lo vuoi.** Gli stessi risultati si possono mandare al pannello
di Grafana Cloud, con il traffico che parte sempre dal tuo PC (quindi lo stesso
indirizzo IP per SupportHost):

```
k6 cloud login --token IL_TUO_TOKEN
k6 cloud run --local-execution -e BASE=https://il-tuo-dominio -e PASSWORD=UnaPasswordLunga2026 tests/carico/studente.js
```

Questo consuma ore-utente virtuali del piano (500 al mese nel gratuito), un po'
meno che facendo partire la prova dai server di Grafana: per la prova vera,
all'incirca 150 utenti × 27 minuti ÷ 60, cioè circa 68, meno lo sconto per
l'esecuzione da casa. La prova senza Grafana Cloud non consuma niente.

**Opzioni**, tutte con `-e`: `UTENTI=500` (quanti studenti di prova scegliere),
`PAUSA=10` (la pausa media in secondi), `VIDEO_OGNI=60` (ogni quanti secondi la
fruizione del video), `DURATA=3m` (solo per la prova piccola).

## Dopo la prova

1. Tieni il riepilogo stampato da k6 (e `risultato-carico.html`, se c'è).
2. Togli gli studenti di prova: `php bin/carico togli`.
3. **Rimetti la copia del database** fatta prima: è lei che garantisce che non
   resti niente (accessi, avanzamenti, tentativi).
4. Rimetti la posta sul server vero, se l'avevi spostata su «log».

## Cosa fa e non fa lo script

Legge pagine e basta: non invia questionari, non segna lezioni come fatte, non
scrive domande, non paga. Le sole cose che scrive sul server sono quelle di ogni
accesso (la sessione, l'ultima visita) e la fruizione dei video, tutte legate agli
studenti di prova.
