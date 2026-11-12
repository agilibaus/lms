<p align="center"><img src="https://github.com/agilibaus/lms/blob/main/pistacchio_icon.png" title="Logo Pistacchio LMS" width="200" height="200"></p>

# Pistacchio LMS

Una piattaforma per la formazione a distanza, leggera e moderna, in PHP puro e MySQL.

Pistacchio LMS serve a chi organizza corsi seguiti da gruppi di persone, con un tutor che le
accompagna: lezioni da studiare con calma, questionari, incontri dal vivo su Google Meet e uno
spazio per condividere risorse. È pensata per essere usata bene anche dal telefono.

Il manuale completo, con il funzionamento di ogni parte e le scelte fatte, è in
[manuale.md](manuale.md).

## Caratteristiche principali

- **Corsi** fatti di moduli e lezioni, con testo ricco, video (Bunny Stream, Cloudflare Stream o
  sul proprio server), materiali da scaricare ed esercizi interattivi H5P creati con Lumi.
- **Un percorso guidato**: ogni modulo può aprirsi in una data, il suo questionario si apre a
  lezioni fatte, e la verifica finale a moduli completati.
- **Questionari** con scelta singola, vero/falso, risposta multipla e risposta aperta, o con un
  esercizio H5P; tentativi illimitati, conta il punteggio migliore.
- **Certificati** in PDF, emessi a corso completato, con un codice di verifica pubblico.
- **Gruppi** di studenti con il loro tutor, i loro incontri e le loro date.
- **Incontri dal vivo** su Google Meet, con inviti, promemoria e presenze; si possono usare anche
  con un link Meet inserito a mano.
- **Agenda** a elenco e a mese, e un calendario da aggiungere a Google, Apple o Outlook.
- **Bacheca** delle risorse di ogni corso, **L'esperto risponde** per le domande degli studenti e
  **Tre parole** alla fine di ogni modulo, che il tutor rilegge per gruppo in una nuvola di parole.
- **Report** per corso, gruppo, studente, incontro e video, «Studenti fermi» e «Griglia del
  corso», scaricabili in CSV e XLSX.
- **Cinque ruoli**: l'Amministratore (uno solo), il supervisore che costruisce i corsi in bozza e
  gestisce report, gruppi e Bacheca di tutti i corsi,
  il tutor, l'assistente e lo studente. Alcuni permessi si accendono e spengono dal pannello.
- **Corsi a pagamento** con Stripe: lo studente paga con il link di pagamento del corso, torna a
  Pistacchio con la pagina «Pagamento ricevuto» e viene
  iscritto quando arriva l'avviso firmato di Stripe; i pagamenti ricevuti si vedono dal pannello.
- **Bozze senza iscritti**: un corso in costruzione non lo vede nessuno studente; pubblicandolo,
  vengono iscritti i membri dei gruppi a cui è assegnato.
- **Email** con testi modificabili dal pannello, e una coda che ritenta quelle non partite.
- **Accessibile e responsive**: ogni pagina è controllata automaticamente a tre larghezze, per
  contrasto, etichette, uso da tastiera e permessi di ogni ruolo.

## Requisiti

- PHP **8.1 o superiore**, con le estensioni `pdo_mysql`, `mbstring`, `dom`, `gd`, `openssl`,
  `curl` (e `zip`, facoltativa: per i report in XLSX e gli esercizi H5P)
- MySQL 8+ o MariaDB 10.6+
- Un server web con la riscrittura degli indirizzi (Apache con `mod_rewrite`, o Nginx)
- Composer
- L'accesso al cron (o all'Utilità di pianificazione di Windows) per le email periodiche
- Facoltativi: un progetto Google Cloud con Calendar API per creare gli incontri su Meet, un
  account Bunny Stream per i video protetti, e un account Stripe per i corsi a pagamento

## Installazione

L'installazione si completa dal browser, con una procedura guidata.

1. **Metti i file sul server e installa le dipendenze**
   ```bash
   git clone https://github.com/agilibaus/lms.git
   cd lms
   composer install --no-dev
   chmod -R 775 storage/
   ```

2. **Imposta la cartella `public/` come radice del sito**, così il resto del codice non è
   raggiungibile dal browser. Con Apache servono `mod_rewrite` e `AllowOverride All`.
   Per una prova in locale basta:
   ```bash
   php -S localhost:8000 -t public
   ```

3. **Apri il sito nel browser.** Finché manca il file `.env` si apre la procedura guidata, in
   quattro passi: requisiti, database (tieni pronti host, nome, utente e password), il tuo
   account di Amministratore, e le impostazioni facoltative, che si possono completare anche
   dopo.

4. **Al termine elimina la cartella `public/install`**, e controlla che in `.env` ci sia
   `APP_DEBUG=0`.

5. **Pianifica i tre comandi periodici**, che mandano le email:
   ```cron
   30 7 * * *    cd /percorso/lms && php bin/rilascio-moduli >> storage/logs/rilascio.log 2>&1
   */15 * * * *  cd /percorso/lms && php bin/invita-utenti >> storage/logs/inviti.log 2>&1
   */15 * * * *  cd /percorso/lms && php bin/avvisi >> storage/logs/avvisi.log 2>&1
   ```
   `bin/avvisi` manda anche le email in coda: senza, molte email non partono. Su Windows con
   Laragon vedi [I lavori periodici su Windows](manuale.md#i-lavori-periodici-su-windows-laragon).

Le impostazioni di Google Meet, Bunny Stream, della posta e dei pagamenti con Stripe si completano
poi dal pannello, in «Impostazioni». I dettagli sono nel manuale, per esempio in
[Sessioni live](manuale.md#sessioni-live-google-meet) e in
[Corsi a pagamento](manuale.md#corsi-a-pagamento-stripe).

## Aggiornare un'installazione

Scarica le modifiche, aggiorna le dipendenze e applica le migrazioni nuove della cartella
`database/migrations/`, in ordine di data. Ogni migrazione si può rieseguire senza danni.

```bash
git pull
composer install --no-dev
mysql -u utente -p lms < database/migrations/AAAA_MM_GG_nome.sql
```

## Per saperne di più

Il [manuale](manuale.md) descrive ogni parte della piattaforma: la struttura del progetto, i
ruoli e i permessi, la sicurezza, i report, l'agenda, la Bacheca, gli esercizi H5P, i corsi a
pagamento, i colori e i caratteri, i test automatici e molto altro.
