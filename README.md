<p align="center"><img src="https://github.com/agilibaus/lms/blob/main/pistacchio_icon.png" title="Logo Pistacchio LMS" width="150" height="150"></p>

# Pistacchio LMS

Learning Management System leggero e moderno in PHP puro + MySQL.

## Stack
- PHP 8.1+ (nessun framework), PDO con prepared statements
- MySQL/MariaDB
- Composer per l'autoload PSR-4 + Dompdf (generazione dei certificati PDF)
- Frontend: CSS moderno (flexbox/grid, variabili CSS), nessuna dipendenza JS pesante
- Design: asciutto, moderno, mobile-first (sidebar a drawer sotto i 768px, via checkbox CSS senza JS)

## Funzionalità

- **Corsi** strutturati in Moduli → Lezioni, con video (Bunny/Cloudflare Stream o self-hosted) e materiali scaricabili
- **Editor di testo ricco** (TinyMCE incluso nel progetto) per il contenuto della lezione: formattazione, elenchi, tabelle, immagini caricate e video incorporati da YouTube/Vimeo
- **Questionari** con quattro tipi di domanda — scelta singola, vero/falso, risposta multipla e
  risposta aperta — riordinabili, con verifica automatica del punteggio
- **Certificati** di completamento generati in PDF, con codice di verifica pubblico
- **Report/dashboard** su progressi utente/corso, risultati dei questionari, presenze alle sessioni live e
  tempi di fruizione dei video, tutti scaricabili in CSV e XLSX
- **Rilascio progressivo**: ogni modulo puo' avere una data di apertura, con avviso via email
  agli iscritti il giorno in cui si apre
- **Ruoli utente**, con privilegi configurabili nella tabella `role_permissions` (nessun privilegio hardcodato nel codice):
  
  | Ruolo | Ambito |
  |---|---|
  | `admin` | Gestione completa: corsi, utenti, gruppi, permessi, certificati |
  | `tutor` | Modifica corsi assegnati, corregge quiz, segue gruppi/coorti, gestisce i propri assistenti |
  | `assistente` | Affianca uno o piu' tutor (tabella `assistant_tutors`), non l'admin: correzioni e report solo sugli ambiti assegnati |
  | `studente` | Si registra da solo, si iscrive ai corsi aperti, svolge quiz, scarica i propri certificati |
- **Profilo personale**: ogni utente compila i propri dati e carica un'immagine, che compare tonda accanto al suo nome
- **Gruppi**: classi/coorti di studenti, con corsi assegnabili all'intero gruppo oltre che al singolo utente
- **Sessioni live** integrate con **Google Meet**, tramite Google Calendar API (`conferenceData`) — richiede un account di servizio Google con accesso al Calendar

## Stato del progetto

In sviluppo iniziale.
- ✅ Schema database (`database/schema.sql`)
- ✅ Scaffold applicativo: router, autenticazione/sessioni, connessione PDO, layout responsive, lista/dettaglio corsi
- ✅ Moduli/lezioni con upload materiali ed embed video (Bunny/Cloudflare Stream o self-hosted)
- ✅ Questionari con quattro tipi di domanda, domande riordinabili, tentativi illimitati
- ✅ Sblocco progressivo dei moduli: per questionario obbligatorio e per data di apertura
- ✅ Certificati PDF con emissione automatica, revoca e verifica pubblica per codice
- ✅ Report per corso, studente, gruppo, incontro dal vivo e fruizione dei video, scaricabili
  in CSV e XLSX, con indice a riquadri, ricerca e paginazione
- ✅ Video di benvenuto al primo accesso dello studente, una volta sola e rivedibile dal profilo
- ✅ Importazione di utenti da file CSV, con anteprima e inviti mandati a scaglioni
- ✅ Tabelle ordinabili dal nome della colonna, dal server e senza JavaScript
- ✅ Agenda con vista a elenco e a mese, e calendario .ics da sottoscrivere
- ✅ Pannello di amministrazione: utenti, gruppi, corsi/iscrizioni e matrice dei permessi
- ✅ Protezione CSRF su tutte le richieste POST
- ✅ Sessioni live su Google Meet, con presenze e fallback a link manuale
- ✅ Procedura di installazione guidata dal browser
- ✅ Registrazione autonoma con verifica email, recupero password, catalogo e auto-iscrizione
- ✅ Editor ricco nella lezione, immagini caricate e materiali ordinabili
- ✅ Profilo personale con immagine
- ✅ Cambio password dal profilo e password temporanea dal pannello
- ✅ Video protetti: indirizzi Bunny Stream firmati e a scadenza
- ✅ Ripresa del video da dove si era rimasti e tracciatura dei tempi di fruizione
- ✅ Email di avviso quando si apre un modulo a rilascio programmato (comando da cron)
- ✅ Tutte le pagine responsive, verificate automaticamente a tre larghezze
- ✅ Controlli automatici di accessibilità e dei permessi per ruolo

## Requisiti

- PHP **8.1 o superiore**, con estensioni `pdo_mysql`, `mbstring`, `dom`, `gd` (le ultime due richieste da Dompdf per i certificati)
- Estensione `zip` per il download dei report in XLSX. **Non è obbligatoria**: dove manca,
  il pulsante XLSX non compare e resta il CSV
- MySQL 8+ o MariaDB 10.6+
- Server web con supporto al rewrite degli URL (Apache + `mod_rewrite`, oppure Nginx configurato in modo equivalente)
- Composer (autoload PSR-4 e installazione di Dompdf: senza `composer install` i certificati non possono essere generati)
- Estensioni `openssl` e `curl` per l'integrazione Google (già presenti in quasi tutte le installazioni)
- Per le sessioni live: un progetto Google Cloud con **Calendar API** abilitata e un account di servizio con delega a livello di dominio (vedi sotto). Senza, le sessioni restano utilizzabili con link Meet inseriti a mano
- **Accesso al cron** (o all'Utilità di pianificazione su Windows) per le email di apertura dei moduli a rilascio programmato. Senza, il resto funziona: mancano solo quegli avvisi

## Installazione

L'installazione si completa dal browser, con una procedura guidata: verifica dei requisiti,
creazione del database, primo amministratore e impostazioni facoltative.

1. **Metti i file sul server e installa le dipendenze**
   ```bash
   git clone https://github.com/agilibaus/lms.git
   cd lms
   composer update
   chmod -R 775 storage/
   ```
   `composer update` (non `install`) installa l'autoload PSR-4 e **Dompdf**, usato per i
   certificati PDF: rigenera `composer.lock` includendolo. Dagli aggiornamenti successivi
   `composer install` è di nuovo sufficiente.

   **In produzione usa `composer install --no-dev`.** Fra le dipendenze di sviluppo c'è
   PHPStan: senza quell'opzione finisce nel `vendor/` del server con tutto il suo seguito,
   senza servire a niente.

2. **Imposta il document root sulla cartella `public/`**
   L'applicazione va servita con `public/` come document root (non la root del repo), così i
   file in `app/`, `config/`, `database/` restano fuori dall'accesso diretto via browser.

   Esempio Apache (VirtualHost):
   ```apache
   <VirtualHost *:80>
       ServerName lms.local
       DocumentRoot /percorso/al/progetto/public
       <Directory /percorso/al/progetto/public>
           AllowOverride All
           Require all granted
       </Directory>
   </VirtualHost>
   ```
   Servono `mod_rewrite` e `AllowOverride All`, altrimenti `.htaccess` viene ignorato e tutti
   gli indirizzi diversi da `/` rispondono 404.

   Per una prova rapida in locale, senza Apache:
   ```bash
   php -S localhost:8000 -t public
   ```

3. **Apri il sito nel browser**
   Finché manca il file `.env` vieni portato automaticamente su `/install`. Ti servono a
   portata di mano i dati del database: host, nome, utente e password. Se il database non
   esiste ancora, la procedura prova a crearlo con le stesse credenziali; se esiste, deve
   essere vuoto.

   I quattro passi sono: **requisiti** (versione PHP, estensioni, permessi di scrittura),
   **database** (connessione e importazione dello schema), **amministratore** (il tuo account,
   al posto di qualsiasi `INSERT` manuale) e **impostazioni** facoltative — indirizzo pubblico,
   fuso orario, Bunny/Cloudflare Stream e Google Meet, tutte compilabili anche in seguito.

4. **Al termine, elimina la cartella `public/install`**
   La procedura si disattiva da sola — scrive `storage/installed.lock` e si rifiuta di
   ripartire finché nel database ci sono utenti — ma rimuovere la cartella è la garanzia
   definitiva. Controlla anche che in `.env` ci sia `APP_DEBUG=0`: in produzione gli errori
   non devono finire sotto gli occhi degli utenti.

Se la cartella del progetto non è scrivibile, l'installer non può salvare `.env`: in quel caso
ti mostra il contenuto da creare a mano, e non scrive il file di lock finché la configurazione
non è a posto.

### Aggiornare un'installazione esistente
La procedura guidata serve solo alla prima installazione. Per aggiornare, tira le modifiche,
esegui `composer install` e applica le eventuali migrazioni in `database/migrations/` (in
ordine di data), ad esempio:
```bash
mysql -u utente -p lms < database/migrations/2026_09_15_quiz_certificates.sql
```
Le ultime due sono `2026_10_04_agenda_calendario.sql` e
`2026_10_04_agenda_calendario_date.sql`, che aggiungono `users.calendar_token` e le sue due
date per l'indirizzo personale del calendario (vedi **Agenda**).

Le migrazioni vanno applicate **in ordine di data**, e ciascuna si puo' rieseguire senza
danni. Le piu' recenti:

| File | Cosa fa |
|---|---|
| `2026_09_17_logo_gruppo.sql` | colonna `logo_path` sui gruppi |
| `2026_09_18_ordine_corsi.sql` | colonna `position` sui corsi |
| `2026_09_29_tutor_assistenti.sql` | tabella `assistant_tutors`, un assistente puo' affiancare piu' tutor |
| `2026_09_30_cambio_password.sql` | cambio password dal profilo |
| `2026_09_30_fruizione_video.sql` | ripresa del video e tracciatura dei tempi |
| `2026_09_30_rimozione_supervising_tutor_id.sql` | toglie la colonna, sostituita da `assistant_tutors` |
| `2026_10_01_quiz_tipi_domanda.sql` | risposta multipla e domanda aperta |
| `2026_10_01_rilascio_moduli.sql` | data di apertura dei moduli e avvisi gia' inviati |
| `2026_10_04_agenda_calendario.sql` | indirizzo del calendario personale |
| `2026_10_04_agenda_calendario_date.sql` | da quando esiste e quando e' stato letto |
| `2026_10_05_benvenuto.sql` | video di benvenuto: chi l'ha gia' visto |
| `2026_10_05_importazione_utenti.sql` | la coda degli inviti per gli utenti importati |
| `2026_10_07_benvenuto_tutor.sql` | il benvenuto del tutor nei corsi, le visite che lo riducono, il permesso `course.welcome` |
| `2026_10_07_benvenuto_contatti.sql` | il permesso `course.welcome_own` al tutor, l'email per gli studenti, il link WhatsApp del gruppo |
| `2026_10_07_nome_cognome.sql` | nome e cognome separati, `full_name` calcolato, la scelta di come comparire agli altri studenti |
| `2026_10_07_domande.sql` | le domande degli studenti al tutor e l'archivio delle risposte; i permessi `question.answer` e `question.answer_own` |
| `2026_10_08_primo_accesso.sql` | `name_display` facoltativo: NULL vuol dire «non ancora scelto», e vale come «solo le iniziali» |

**Dopo `2026_10_01_rilascio_moduli.sql` va anche impostato il cron** del rilascio progressivo:
vedi più sotto, altrimenti i moduli si aprono lo stesso ma nessuno avvisa gli studenti.

**`2026_10_05_benvenuto.sql` decide un comportamento, non solo una colonna**: nello stesso
momento in cui la crea, segna come «già visto» tutti gli utenti che esistono in quel momento.
È il modo in cui chi è già iscritto non viene interrotto dalla pagina di benvenuto — e non si
torna indietro, se non svuotando la casella a mano.

## Struttura del progetto

```
/public              → document root
  /assets/css         → stylesheet
  /assets/js          → course-order (riordino schede), lesson-video (copertina),
                        lesson-focus (senza distrazioni), lesson-tracking (tempi di
                        fruizione), dropdown
  /assets/fonts       → Albert Sans per le pagine pubbliche (vedi LEGGIMI.md nella cartella)
                        i caratteri scelti dal catalogo NON stanno qui ma in storage/fonts
  /assets/vendor/tinymce → editor di testo ricco (vedi README-pistacchio.md nella cartella)
  /install            → procedura di installazione guidata (da eliminare dopo l'uso)
  index.php           → front controller
  .htaccess           → rewrite verso index.php
/app
  /Controllers        → logica delle route: Auth, Registration, PasswordReset, Profile,
                        Course, Module, Lesson, Quiz, Certificate, Report, Catalog,
                        LiveSession, VideoProgress
    /Admin             → pannello di amministrazione: Admin, User, Group, Course,
                        Permission, Settings
  /Models             → accesso dati via PDO/query preparate (UserModel, CourseModel, ModuleModel, LessonModel, Quiz*, CertificateModel, GroupModel, ReportModel...)
  /Auth               → login, sessione, permessi per ruolo (Auth.php)
  /Core               → Router minimale, Database (PDO), Env, View, Settings, Url
                         Upload, FileType, OrphanFiles          → file caricati
                         VideoEmbed, VideoPoster, BunnyToken, WatchIntervals → video
                         CourseAccess, QuizScoring, CertificateService → regole didattiche
                         CourseCover, GroupLogo, AvatarImage, AuthLayout → immagini e aspetto
                         Csv, Xlsx, Ics                         → formati di scambio
                         ReportSections                         → i cinque tagli dei report
                         Csrf, HtmlSanitizer, PasswordPolicy, PasswordGenerator → sicurezza
    /Google            → client minimale per Calendar API (ServiceAccountClient, MeetCalendar, trasporto HTTP)
    /Mail              → client SMTP scritto in casa, trasporti e testi dei messaggi
  /Views
    /partials          → layout condiviso (shell.php)
  routes.php
/bin
  rilascio-moduli      → comando da cron: avvisa gli studenti dei moduli che si aprono
/config
  config.php           → bootstrap (env, error reporting, timezone)
/storage
  /videos
  /materials
  /lesson-images       → immagini inserite nel testo delle lezioni
  /avatars             → immagini del profilo
  /course-covers       → copertine dei corsi, in due misure
  /group-logos         → logo dei gruppi
  /certificates
  /google              → chiave dell'account di servizio (permessi 0600)
  /mail                → messaggi .eml quando MAIL_TRANSPORT=log
  /logs                → uscita dei comandi da cron
  /fonts               → caratteri scaricati dal catalogo di Google Fonts
/database
  schema.sql
  google-fonts.json    → elenco delle famiglie del catalogo (solo nomi, 50 KB)
  /migrations         → migrazioni incrementali per installazioni gia' esistenti
/tests                 → vedi la sezione «Test» più sotto per l'elenco completo
```

## Questionari, certificati e report

### Questionari
Ogni modulo puo' avere **un questionario**. Il tutor imposta la soglia di superamento in percentuale;
i **tentativi sono illimitati** e allo studente vale sempre il punteggio migliore. Le risposte
corrette non vengono mai inviate al browser durante lo svolgimento, e la correzione avviene
lato server verificando che l'opzione scelta appartenga davvero alla domanda. Le domande si
riordinano con due frecce, come moduli e lezioni.

**Dove si modifica e si elimina.** Da due posti: il collegamento «Questionario» nell'intestazione del
riquadro del modulo, nella pagina del corso, e il pulsante «Modifica questionario» nella pagina del
questionario — come «Modifica lezione» nella lezione. L'eliminazione sta in fondo alla pagina di
modifica, con la conferma, perché cancella domande e tentativi già svolti e non si torna
indietro. Fino alla 0106 il secondo posto non esisteva: la funzione c'era ma dalla pagina del
questionario non ci si arrivava, che per chi la cerca è lo stesso. Da lì una regola e tre prove in
`permessi.js`: un permesso che dall'interfaccia non si raggiunge non è un permesso, quindi si
verifica anche la **via**, non solo il diritto.

Quattro tipi di domanda:

| Tipo | Come si corregge |
|---|---|
| Scelta singola | Una sola opzione giusta |
| Vero / falso | Idem, con due opzioni fisse |
| Risposta multipla | **Tutto o niente**: vale solo se lo studente segna esattamente le opzioni giuste. La domanda dice quante sono |
| Risposta aperta | **Non fa punteggio**: la risposta si raccoglie e basta |

Le domande aperte restano **fuori dal conteggio**, sia al numeratore sia al denominatore: un
questionario di sole domande aperte risulta consegnato e superato, senza percentuale. Le risposte si
leggono nel report del singolo studente. La logica sta tutta in `App\Core\QuizScoring`, con
46 test.

**Quanto si può scrivere in una risposta aperta**: 3.000 caratteri
(`QuizController::MAX_OPEN_CHARS`), dichiarati in tre posti con tre mestieri diversi. Il
`maxlength` del campo è quello che il browser fa rispettare, e funziona **senza JavaScript**.
Il contatore sopra l'angolo in alto a destra del campo scala mentre si scrive, e sotto i 100
caratteri rimasti il numero si fa scuro e grassetto — non solo di un altro colore, perché il
colore da solo non è un'informazione. Senza JavaScript resta fermo su «3000 caratteri
rimasti», che a campo vuoto è vero. **Il limite che conta è il taglio in PHP**: il corpo di
una richiesta lo scrive chi vuole, e togliere `maxlength` dagli strumenti per sviluppatori è
un attimo. Il contatore non è una regione viva (niente `aria-live`): annunciarsi a ogni tasto
coprirebbe con la propria voce quello che la persona sta scrivendo; il collegamento è
`aria-describedby`, che lo fa leggere entrando nel campo. Sono caratteri e non byte:
`answer_text` è un TEXT da 65.535 byte e 3.000 caratteri accentati in utf8mb4 ne occupano al
massimo 12.000, quindi non serve nessuna migrazione.

### Sblocco progressivo dei moduli
Due regole indipendenti possono chiudere un modulo, e ne basta una. **Lo staff non e' mai
soggetto al blocco**: un modulo chiuso va preparato prima che si apra.

**Per questionario obbligatorio.** Se un modulo ha il flag "questionario obbligatorio", tutti i moduli
successivi restano bloccati finche' lo studente non supera quel questionario. Un modulo marcato come
obbligatorio ma privo di questionario — o con un questionario senza domande — non blocca nulla, per evitare
vicoli ciechi.

**Per data (rilascio progressivo).** Ogni modulo ha un campo **"Disponibile dal"**: vuoto vuol
dire aperto, con una data il modulo si apre in quel momento, uguale per tutti gli studenti.
Lo studente vede il titolo in grigio con la data e non puo' aprire **ne' le lezioni, ne' i
questionari, ne' i materiali scaricabili, ne' gli incontri dal vivo** di quel modulo: sono quattro
ingressi distinti nel codice, e il controllo e' in tutti e quattro, perche' nascondere un
collegamento non e' bloccarlo.

Le due regole si incatenano nel verso giusto: un modulo chiuso per data che ha il questionario
obbligatorio chiude anche quelli dopo, perche' il suo questionario non si puo' fare.

Il confronto fra la data di apertura e l'ora corrente si fa **in SQL**, mai in PHP: il server
web e il database possono trovarsi su fusi diversi, e qui un'ora di differenza vuol dire un
modulo aperto quando non doveva.

**La percentuale di avanzamento non cambia**: continua a contare tutte le lezioni del corso,
aperte e chiuse, cosi' certificati e report restano confrontabili. Accanto, sulla scheda del
corso, lo studente legge una frase che risponde all'altra domanda — «Sei in pari — prossimo
modulo il 31/10/2026» — e che compare solo se c'e' davvero un modulo chiuso.

#### L'email di apertura, e il cron che la manda
Il giorno in cui un modulo si apre gli iscritti ricevono un'email. Non la manda una richiesta
web — non c'e' nessuna richiesta, e' passata una data — ma un comando da eseguire una volta al
giorno:

```
30 7 * * * cd /percorso/del/sito && php bin/rilascio-moduli >> storage/logs/rilascio.log 2>&1
```

`php bin/rilascio-moduli --prova` elenca che cosa manderebbe senza mandare niente.

In sviluppo, su Windows con Laragon, questo comando si pianifica con l'Utilità di
pianificazione: la ricetta sta più sotto, in «I lavori periodici su Windows».

Tre cose da sapere prima di affidarglisi:

- **Non manda due volte.** La riga in `module_unlock_notifications` si scrive **prima**
  dell'invio, e a decidere e' la chiave unica del database: due esecuzioni sovrapposte non
  mandano la stessa email due volte. Il rovescio e' voluto: se il server di posta rifiuta,
  quell'avviso e' perduto e lo studente trovera' comunque il modulo aperto rientrando. Fra
  perdere un avviso e mandarne dieci a tutti insieme, il primo e' il verso giusto in cui
  sbagliare.
- **Si rifiuta di partire senza `APP_URL`.** Da terminale non c'e' una richiesta da cui
  ricavare l'indirizzo del sito, e le email uscirebbero con collegamenti a `localhost`.
- **Lascia detto di aver girato**, in `settings.DRIP_LAST_RUN_AT`. Un cron fermo non si
  lamenta: senza quella traccia, il primo segnale sarebbe uno studente che non ha saputo di un
  modulo aperto da due settimane.

**Se il cron non c'e', i moduli si aprono lo stesso** — il blocco si calcola quando lo studente
apre la pagina — e a mancare sono solo le email.

### Certificati
Il certificato viene emesso **automaticamente** quando lo studente ha completato tutte le
lezioni del corso **e** superato tutti i questionari presenti. Il PDF (A4 orizzontale, generato con
Dompdf) viene salvato in `storage/certificates/` e non e' mai raggiungibile direttamente da
`public/`: il download passa da un endpoint autenticato. Ogni certificato ha un codice di
verifica pubblico consultabile su `/verify/{codice}`, pagina che non richiede login e mostra
solo intestatario, corso e data. Admin e tutor possono emettere un certificato manualmente
(anche in deroga ai requisiti) o revocarlo: un certificato revocato non e' piu' scaricabile,
risulta "revocato" nella verifica pubblica e non viene rigenerato dall'emissione automatica.

### Report
`/reports` è un **indice che sceglie**, non una pagina che stampa tutto: cinque riquadri, uno
per taglio, ciascuno con il proprio numero (quanti corsi, quanti studenti…). L'elenco completo
di un taglio sta in una pagina sua, `/reports/elenco/{sezione}`, con **ricerca** e
**paginazione a 50 righe**.

Prima le cinque tabelle erano stampate per intero una sotto l'altra: con 605 studenti la
pagina era alta **22.042 px** — ventidue schermi — e non c'era modo di cercare una riga se non
scorrendo. Adesso l'indice sta in uno schermo.

Nelle tabelle le colonne di conteggio sono **allineate a destra, in cifre a larghezza fissa e
della stessa larghezza in tutte e cinque le pagine**: passando da un elenco all'altro i numeri
cadono dove ci si aspetta. (Prima erano allineati a sinistra e cambiavano ascissa in ogni
tabella.) Sotto i 50 rem di spazio disponibile ogni riga diventa una scheda e l'allineamento
delle colonne decade da solo, perché lì colonne non ce ne sono più.

I cinque tagli sono descritti una volta sola in `App\Core\ReportSections` e le loro colonne in
`app/Views/reports/_colonne.php`: indice ed elenchi leggono di lì, così non possono divergere.
La ricerca filtra in memoria sui campi che la sezione dichiara — con seicento righe è
istantanea; è il punto da cambiare se un giorno le righe saranno decine di migliaia.

**Il campo di ricerca dice che cosa si può cercare lì**: il suggerimento elenca le colonne in
cui quel report cerca davvero (`studente, email…` fra gli studenti, `incontro, corso o
gruppo…` fra gli incontri), perché i cinque report hanno dati diversi e un suggerimento
uguale per tutti prometteva ricerche che non esistono. Le intestazioni citate sono quelle
della tabella sotto, e `tests/report_test.php` verifica che lo restino. Ogni sezione dichiara
anche il proprio articolo plurale, così la frase è «Cerca fra **gli** studenti» e non «fra i
studenti»: in italiano dipende da come comincia la parola, non dal genere.

**Ogni vista si scarica in XLSX e in CSV.** In cima a una pagina di dettaglio i due formati
stanno dietro a un pulsante "Scarica" con la tendina; **nelle righe degli elenchi no**: lì
sono due collegamenti scritti, `XLSX` e `CSV`, accanto a `Dettaglio`. Una tendina per riga
costa un clic in più cinquanta volte per pagina, e soprattutto un pannello che si apre
dentro a una tabella è un pannello che qualcosa può ritagliare — è successo, ed è il motivo
per cui ora un controllo automatico apre ogni tendina della piattaforma e verifica che il
suo pannello non esca da un contenitore che scorre.

| Report | Contenuto |
|---|---|
| Per corso | Iscritti con progresso, lezioni completate, questionari superati, stato certificato |
| Per gruppo | Membri del gruppo incrociati con i corsi assegnati al gruppo (con il simbolo del gruppo accanto al nome, come nel pannello) |
| Per studente | Tutti i corsi dello studente, con dettaglio tentativi, punteggi e risposte aperte |
| Per incontro dal vivo | Presenze, con l'origine del dato (piattaforma o segnata a mano) |
| Fruizione dei video | Tempo effettivamente guardato per studente e per lezione, in secondi |

L'XLSX non richiede dipendenze: il file lo scrive `App\Core\Xlsx`, che ha bisogno
dell'estensione `zip`. Dove manca, il pulsante XLSX non compare e resta il CSV.

I permessi seguono `role_permissions`: `report.view` (admin, tutor) da' accesso completo,
`report.view_assigned` (assistente) limita la vista agli studenti dei gruppi seguiti dai tutor
che affianca (tabella `assistant_tutors`).

## Pannello di amministrazione

Raggiungibile dalla sezione **Amministrazione** della sidebar, che mostra solo le voci
consentite dai permessi dell'utente.

### Utenti (`/admin/users`)
Creazione utenti con ruolo, stato attivo/disattivo e password iniziale, modifica e
reimpostazione password. **La password iniziale la genera la piattaforma** e si consegna una
volta sola: è temporanea, e al primo accesso l'utente deve sceglierne una propria. Chiunque
può cambiarla in qualsiasi momento dal proprio **Profilo**; il cambio chiude tutte le altre
sessioni aperte e manda un avviso per email. La regola è almeno 8 caratteri con lettere e
cifre (`App\Core\PasswordPolicy`). Serve `user.manage`; chi ha solo `assistant.manage`
(il tutor) vede e gestisce esclusivamente i propri assistenti e non può assegnare altri ruoli.

Alcune protezioni sono deliberatamente rigide, per non restare chiusi fuori:
un amministratore non può cambiare il proprio ruolo, disattivarsi o eliminarsi; deve sempre
restare almeno un admin attivo; un utente che risulta autore di corsi non è eliminabile
(`courses.created_by` è `ON DELETE RESTRICT`) e va semmai disattivato. L'eliminazione di un
utente rimuove a cascata iscrizioni, progressi, tentativi e certificati: per conservare lo
storico è preferibile disattivarlo.

La scheda dell'utente mostra anche **i gruppi a cui partecipa** (tutor e numero di corsi del
gruppo), e permette di aggiungerlo o toglierlo da lì: le stesse azioni della scheda del gruppo,
con gli stessi effetti sulle iscrizioni. L'elenco a tendina contiene solo i gruppi che chi
guarda può gestire — tutti con `group.manage`, i propri con `group.manage_own`, nessuno senza
quei permessi (in quel caso i gruppi si vedono ma non si modificano).

### Gruppi (`/admin/groups`)
Classi/coorti con tutor responsabile, membri e corsi assegnati. Serve `group.manage` (tutti i
gruppi) oppure `group.manage_own` (solo quelli di cui si è tutor; chi crea un gruppo ne diventa
responsabile e non può cederlo).

**Assegnare un corso al gruppo iscrive i membri al corso**, e chi entra nel gruppo in un secondo
momento viene iscritto ai corsi già assegnati. L'operazione è idempotente: riassegnare un corso
non azzera il progresso di chi era già iscritto. Le operazioni inverse — togliere un membro dal
gruppo o un corso dal gruppo — **non** cancellano le iscrizioni, perché con esse sparirebbero
progresso, tentativi dei questionari e certificati; per rimuoverle davvero si usa la scheda del corso.

### Corsi e iscrizioni (`/admin/courses`)
Creazione (`course.create`), modifica e iscrizioni (`course.edit`), eliminazione
(`course.delete`). Lo slug è generato dal titolo e reso univoco in automatico. I contenuti
(moduli, lezioni, questionari) restano nella scheda del corso. La rimozione di un'iscrizione cancella
progresso e certificato di quel corso: viene chiesta conferma.

### Permessi (`/admin/permissions`)
Matrice ruoli × permessi su `role_permissions`, con le chiavi effettivamente controllate dal
codice (`RolePermissionModel::catalog()`). Le modifiche hanno effetto immediato.

Questa pagina è l'unica riservata al **ruolo** `admin` anziché a un permesso: un permesso
revocabile da qui potrebbe lasciare la piattaforma senza nessuno in grado di ripristinarlo.
Per lo stesso motivo `user.manage` viene sempre mantenuto al ruolo admin. Eventuali chiavi
personalizzate inserite a mano in tabella non vengono toccate dal salvataggio.

## Sicurezza dei form (CSRF)

Ogni richiesta POST deve includere il token di sessione (`App\Core\Csrf`), verificato dal
Router prima di invocare il controller; una richiesta senza token valido riceve **419** e non
produce alcun effetto. Nelle view il campo si inserisce con `<?= Csrf::field() ?>`. Il token
viene rigenerato a ogni login e logout, insieme all'id di sessione.

## Sessioni live (Google Meet)

Una sessione live è un incontro collegato a un **modulo di corso** e/o a un **gruppo**: i
partecipanti attesi sono gli iscritti al corso del modulo e i membri del gruppo. Le sessioni si
gestiscono da `/live` (permesso `course.edit`: admin e tutor); gli studenti vedono solo quelle
che li riguardano.

### Come nasce il link Meet
Alla creazione della sessione l'applicazione crea un evento su Google Calendar chiedendo
contestualmente una conferenza Meet (`conferenceData.createRequest`, con
`conferenceDataVersion=1`) e salva `google_event_id` e `meet_link`. Modificando la sessione
l'evento viene allineato; eliminandola, l'evento viene rimosso dal calendario.

**Se Google non è configurato o risponde con un errore, la sessione viene salvata lo stesso**:
l'utente riceve un avviso e può incollare un link Meet creato a mano, oppure riprovare la
sincronizzazione dalla scheda della sessione. Un link inserito manualmente ha la precedenza e
scollega la sessione da Google.

### Configurazione
1. Nel progetto Google Cloud, abilita la **Google Calendar API** e crea un **account di
   servizio**; scarica la chiave in formato JSON e mettila **fuori dal document root**.
2. Nella Admin console di Google Workspace, sezione *Sicurezza → Controllo delle API →
   Delega a livello di dominio*, autorizza il **Client ID** dell'account di servizio per lo
   scope `https://www.googleapis.com/auth/calendar`.
3. Compila le variabili in `.env`:
   ```
   GOOGLE_SERVICE_ACCOUNT_JSON=/percorso/protetto/credenziali.json
   GOOGLE_IMPERSONATE_EMAIL=corsi@tuodominio.it
   GOOGLE_CALENDAR_ID=primary
   GOOGLE_CALENDAR_TIMEZONE=Europe/Rome
   ```

`GOOGLE_IMPERSONATE_EMAIL` è l'utente Workspace per conto del quale l'account di servizio
crea gli eventi: **senza delega Google non genera il link Meet**, e l'evento verrebbe creato
"nudo". Se lo scope non è autorizzato, Google risponde `403 Insufficient Permission`: il
messaggio viene mostrato per intero nella scheda della sessione.

Il client è scritto in casa (`app/Core/Google/`), senza dipendenze: firma una JWT RS256 con
`openssl`, la scambia per un access token (flusso JWT bearer) e chiama le API REST con cURL.
Il token viene riusato per tutta la durata della richiesta.

### Presenze
L'ingresso passa da un link interno (`/live/{id}/join`): la piattaforma registra `joined_at`
e reindirizza al Meet. Il primo ingresso è quello che conta — riaprire il link non lo
sovrascrive — e lo staff che entra non compare fra i presenti. Il tutor può correggere il
registro dalla scheda della sessione, anche a sessione conclusa; l'origine del dato resta
distinguibile (`platform` o `manual`). Il numero di sessioni seguite compare nel report del
singolo studente.

Nota: l'ingresso tracciato certifica l'apertura del link dalla piattaforma, non l'effettiva
permanenza nella riunione. Per il dato reale di partecipazione servirebbe la Reports API di
Google Workspace, che richiede scope aggiuntivi ed è disponibile solo a sessione conclusa.

## Protezione dei video (Bunny Stream)

Un video caricato su Bunny si guarda con un **indirizzo firmato e a scadenza**: l'embed porta
un token calcolato dal server (`App\Core\BunnyToken`) a partire dalla chiave della libreria,
e senza quel token Bunny risponde **403**. Vale anche per il flusso `.m3u8` chiesto da fuori.

Gli interruttori da accendere su Bunny sono due e **non sono la stessa cosa**: la *Embed View
Token Authentication* sta nelle impostazioni della Video Library ed è quella che serve qui;
la *CDN Token Authentication* sta sulla Pull Zone ed è una cintura in più. La chiave non va
mai nel repository: si incolla in **Amministrazione → Bunny Stream**, che la salva in tabella.

## Ripresa del video e tempi di fruizione

Lo studente che rientra in una lezione già cominciata trova sulla copertina due pulsanti
della stessa larghezza — **Riprendi da hh:mm:ss** e **Guarda dall'inizio** — invece del solo
play. La soglia è 30 secondi: sotto, "riprendere" non avrebbe senso.

La piattaforma registra anche **quanto di ogni video è stato davvero guardato**, non solo il
punto più avanzato: il player manda gli intervalli visti, che vengono fusi in scrittura, così
rivedere due volte lo stesso minuto conta una volta sola. Il risultato sta nel report
*Fruizione dei video*, per studente e per lezione.

Due difese contro i tempi gonfiati: un **tetto di plausibilità** (nessuno può dichiarare più
di 2,5 volte il tempo realmente trascorso, più 30 secondi di tolleranza), e il fatto che il
tempo trascorso si calcola **in SQL** con `TIMESTAMPDIFF`, perché l'orologio di PHP e quello
di MySQL possono stare su fusi diversi.

**Resta un dato dichiarato dal client.** Nessun controllo lato server può renderlo
infalsificabile: serve a rendicontare lo studio di chi studia, non a inchiodare chi bara.

## Mobile

**Ogni pagina deve essere utilizzabile da telefono**, e dove adattarla non è possibile deve
avere un ripiego dichiarato. Non è un auspicio: è un requisito di accettazione, verificato
automaticamente a ogni giro.

Il modo normale per una tabella larga è `.tabella-schede`, che sotto i 50 rem di spazio
disponibile la trasforma in un elenco di schede — con *container query*, quindi in base allo
spazio che la tabella ha davvero, non alla larghezza della finestra. Dove le schede non hanno
senso (la matrice dei permessi, che ha una colonna per permesso) c'è il ripiego: scorrimento
orizzontale **dentro il proprio riquadro**, senza trascinarsi dietro la pagina.

## Comandi e collegamenti: come si riconoscono

Tre regole, decise il 06/10, uguali in ogni pagina e senza elementi in più:

| | Con il mouse | Senza mouse (telefono, tablet) |
|---|---|---|
| **Comandi** — nelle righe delle tabelle, fra i comandi di un modulo, nelle liste di assegnazione, nell'Agenda, e quelli isolati in una pagina | neutri, nel colore del testo; sottolineati al passaggio del mouse e con il fuoco della tastiera | sempre sottolineati |
| **Collegamenti dentro una frase** | verdi e sempre sottolineati | verdi e sempre sottolineati |

Fuori dalle tabelle i comandi stanno a **0,85 rem**, come stabilito con la 0107; dentro una
cella prendono la misura della cella. Anche «Aggiungi al calendario» dell'Agenda, che fino al
07/10 era alto quanto il testo e pesava quanto il titolo dell'incontro accanto.

I comandi che **fanno perdere qualcosa** sono rossi (`.link-btn-danger`), con le stesse regole
degli altri. Il criterio: **rosso se si perde qualcosa che ripetere il gesto contrario non
restituisce**. «Rimuovi» uno studente da un corso è rosso (cancella progresso, tentativi e
certificato); «Rimuovi» un membro da un gruppo no (le iscrizioni restano). Il pericolo non lo
dice il solo colore: lo dicono anche la parola e la conferma che compare prima.

Fino al 06/10 i comandi che inviano un modulo (`.link-btn`: «Elimina», «Revoca», «Rimuovi»…)
erano gli unici sottolineati, e in grigio e di mezzo pixel più piccoli dei collegamenti nella
stessa riga: una differenza tecnica, invisibile a chi usa la piattaforma, che si vedeva lo
stesso. E i collegamenti dentro le frasi avevano il colore del testo e nessuna sottolineatura,
cioè erano indistinguibili.

«Senza mouse» è il dispositivo, non la larghezza (`@media (hover: none)`): un portatile stretto
ha il mouse. **L'elenco dei comandi sta nel foglio di stile, sotto `.link-btn`, e uguale in
`tests/accessibilita.js`**: un comando nuovo in un posto nuovo va aggiunto in tutti e due, o
sul telefono resta senza segnale e i controlli non lo guardano.

`.link-btn` ha `font-family: inherit`: un pulsante non eredita il carattere della pagina, e
«Elimina» in Arial accanto a «Modifica» nel carattere di sistema, su Windows (Segoe UI),
stava più in alto.

`accessibilita.js` verifica su ogni pagina che i collegamenti nelle frasi siano sottolineati e
di un altro colore, che con il mouse i comandi siano neutri a riposo e che i comandi di una
stessa fila abbiano il testo sulla stessa riga e lo stesso carattere; poi rifà il giro con un telefono simulato
**senza mouse** e verifica che ogni comando sia sottolineato. Il giro «telefono» normale non
basta: cambia la larghezza, ma il browser ha ancora il mouse.

## Tabelle ordinabili

**Ogni tabella in cui l'ordine è un dato si ordina cliccando sul nome della colonna.** Il
clic ricarica la pagina con `?ordina=colonna&verso=desc`: costa una ricarica, e in cambio dà
tre cose che il riordino in JavaScript non può dare.

1. **Ordina tutte le righe, non quelle a schermo.** Gli elenchi dei report sono paginati a
   cinquanta righe: riordinando nel browser, «il punteggio più alto» sarebbe il più alto di
   quella pagina, non del report — una risposta sbagliata che sembra giusta.
2. **L'ordine sta nell'indirizzo**, quindi si salva nei preferiti e si manda a un collega.
   Sopravvive alla paginazione e alla ricerca.
3. **Funziona senza JavaScript**, come il resto della piattaforma.

Il meccanismo è `App\Core\Ordinamento`, e una vista lo usa così:

```php
$ordine = Ordinamento::daRichiesta([
    'nome'  => ['full_name', Ordinamento::TESTO, 'email'],
    'stato' => ['is_active', Ordinamento::NUMERO],
]);
$utenti = $ordine->applica($utenti);
// nella testata:  <?= $ordine->th('Nome', 'nome') ?>
```

Cinque cose da sapere prima di aggiungerne una:

- **Le chiavi dell'indirizzo non sono i nomi dei campi** (`nome`, non `full_name`). Quello
  che si scrive nell'indirizzo è un'interfaccia pubblica: legarla ai nomi delle colonne del
  database vorrebbe dire raccontarli a chi guarda e non poterli più cambiare. Una chiave che
  non è stata dichiarata viene ignorata, quindi **niente di quello che arriva dall'indirizzo
  diventa mai un nome di campo**.
- **Si ordina sul dato, non su come è scritto.** «Quando» mostra `04/10/2026 18:30` e ordina
  su `starts_at`; «Presenti» mostra `3/12` e ordina sul numero dei presenti. Dove il valore
  mostrato è un'etichetta calcolata (presente/assente, valido/revocato) la vista calcola un
  campo apposta: ordinare su `revoked_at` manderebbe i validi sempre in fondo, perché non ce
  l'hanno.
- **I vuoti stanno in fondo in tutti e due i versi.** Un trattino che sale in cima
  invertendo l'ordine non è un'informazione: sono le righe a cui quel dato manca.
- **Senza parametri non si ordina niente.** Ogni elenco ha già il suo ordine naturale (gli
  incontri per data, le lezioni per posizione) e sostituirlo al primo caricamento sarebbe un
  peggioramento. L'ordinamento è stabile, così a parità di valore le righe non si rimescolano
  a ogni caricamento.
- **Le colonne delle persone dichiarano lo spareggio**: il terzo elemento, `'email'`, è il
  campo che decide quando il nome è uguale, nello stesso verso. Due omonimi senza spareggio
  restavano nell'ordine in cui li mandava il database, che nessuno aveva deciso, e con la
  paginazione a cinquanta righe potevano scambiarsi fra una pagina e l'altra: uno compariva
  due volte e l'altro mai. L'email è unica ed è scritta sotto il nome, quindi l'ordine è
  sempre lo stesso e si vede. Per la stessa ragione **le query che ordinano persone per nome
  finiscono con `, u.email`**: è l'ordine delle pagine aperte senza aver cliccato niente.

Sul telefono, dove la tabella diventa un elenco di schede, l'intestazione non sparisce: torna
come **una fila di comandi sopra alle schede**. Nasconderla del tutto avrebbe tolto
l'ordinamento proprio dove la tabella è più scomoda, e avrebbe lasciato dei collegamenti
raggiungibili con il tasto di tabulazione ma invisibili.

Restano **non ordinabili**, e per scelta: la matrice dei permessi (caselle, non righe da
confrontare), il questionario da svolgere, gli elenchi di moduli e lezioni dentro un corso — lì
l'ordine è deciso a mano dal tutor, cioè *è* il contenuto — e le singole colonne che mostrano
un campo **oppure** un altro («Corso o gruppo»): ordinarle su uno dei due manderebbe in fondo
tutte le righe dell'altro, con l'aria di un difetto.

## Importazione di utenti da file

Si carica un **CSV**, si guarda l'anteprima, si conferma. Finché non si preme «Importa» non
viene scritta una riga: con duecento persone vere la differenza fra vedere prima cosa
succederà e scoprirlo dopo è un pomeriggio di telefonate.

Il file ha una riga di intestazione, in qualunque ordine: `email`, `nome` e `cognome`
(obbligatorie) e `gruppo`, facoltativo. **Nome e cognome stanno sempre in due colonne
separate** (deciso da Elena il 07/10): un file con il nome in una colonna sola si rifiuta per
intero, e il messaggio dice di separarla. Una riga senza cognome si scarta. Gli utenti nascono
come **studenti**. Massimo 1.000 righe per file.

`App\Core\UserImport` legge e giudica, **non scrive**: è per questo che tutta la parte che
sbaglia davvero si prova con delle stringhe (`php tests/importazione_test.php`). Le tre cose
che vanno storte, in ordine di frequenza vera:

- **La codifica.** Excel su Windows produce UTF-8 con il BOM oppure Windows-1252. Col BOM la
  prima intestazione si chiama davvero `\xEF\xBB\xBFemail` e il file sembra non avere la
  colonna email; col Windows-1252 «Nicolò» arriva rotto. Si riconoscono e si sistemano tutti
  e due.
- **Il separatore.** Excel in italiano scrive il punto e virgola, perché la virgola la usa
  per i decimali. Si conta quale compare di più nell'intestazione, non si chiede all'utente.
- **I duplicati dentro al file.** Due righe con la stessa email: la seconda si ferma qui,
  dicendo quale riga ripete. Lasciata passare, la fermerebbe il database con «esiste già»,
  che fa pensare a un utente vecchio invece che a un refuso.

Una riga con un'email già presente su Pistacchio viene **saltata e dichiarata**: l'account
esistente non si tocca e non riceve nessuna password nuova. I gruppi non si creano al volo —
un gruppo nominato e inesistente ferma quella riga, invece di far nascere un gruppo da un
refuso. Chi entra in un gruppo viene iscritto anche ai suoi corsi: è quello che i gruppi
hanno sempre fatto, ma con un file si fa duecento volte in un colpo.

### Le password, e perché a scaglioni

L'importazione **non manda niente**. Gli account nascono con un'impronta di byte casuali —
che non corrisponde a nessuna password scrivibile — e la password vera la genera
`bin/invita-utenti` nel momento in cui compone l'email. Così nessuna password resta in attesa
dentro al database, che sarebbe la cosa peggiore di tutta questa storia.

```
*/15 * * * *  cd /percorso/lms && php bin/invita-utenti >> storage/logs/inviti.log 2>&1
```

Venti per volta, ogni quarto d'ora: duecento persone in due ore e mezza, senza che duecento
email identiche in trenta secondi incontrino il limite di invii dell'hosting e i filtri
antispam. L'ordine delle operazioni è quello che conta: si genera la password, se ne scrive
l'impronta, si manda l'email, e **solo se l'email è partita** l'utente esce dalla coda. Se la
posta fallisce quell'utente resta in coda e al giro dopo riceve una password nuova: quella di
adesso non l'ha saputa nessuno. Il verso opposto lascerebbe un account con una password che
non conosce nemmeno il suo proprietario.

**Chi conosce una password esce dalla coda** (dal 07/10). Il ragionamento qui sopra regge solo
se chi è in coda non conosce nessuna password, e prima nessuno lo garantiva: una password
temporanea data dal pannello, un recupero via email, o un'email consegnata nonostante un errore
della posta lasciavano l'utente in coda con una password in mano, e il giro dopo gliela
sostituiva — credenziali giuste respinte, e con una posta instabile ogni quarto d'ora. Ora:

- **chi fa accesso esce dalla coda**: ha una password che funziona;
- **chi ottiene una password per un'altra strada esce dalla coda** — cambio dal profilo, cambio
  obbligato, recupero via email, password temporanea dal pannello (`UserModel::updatePassword()`);
- **il comando non scrive la password a chi non è più in coda**: la condizione sta nella
  scrittura stessa (`AND invite_pending = 1`), così un accesso avvenuto mentre il comando gira
  non viene annullato, e a quell'utente non parte nessuna email.

Resta un caso: un'email consegnata nonostante l'errore, a un utente che non ha ancora fatto
accesso. Al giro dopo ne riceve un'altra, e vale la password della seconda; appena entra con
quella, esce dalla coda.

**Se il cron non gira, nessuno entra.** È più grave del rilascio dei moduli, dove un cron
fermo fa mancare solo un avviso: qui chi è stato importato non ha una password finché
l'invito non parte. Per questo la pagina Utenti mostra quanti inviti restano, quando è
partito l'ultimo scaglione, e un pulsante per mandarne uno a mano. Se il numero non scende,
si vede.

L'email dell'invito è sua e non quella della password temporanea: quel testo dice «la
password precedente non funziona più» e «le sessioni aperte sono state chiuse», due frasi
vere per un account esistente e false per uno appena creato.

## I lavori periodici su Windows (Laragon)

I due comandi — `bin/rilascio-moduli` e `bin/invita-utenti` — in sviluppo girano sul computer
di Elena, che è Windows con Laragon, tramite l'Utilità di pianificazione. La ricetta è la
stessa per tutti e due, cambia solo il nome e la frequenza.

**Il percorso del PHP.** Dal terminale di Laragon, `where php`: esce qualcosa come
`C:\laragon\bin\php\php-8.4.3-Win32-vs17-x64\php.exe`, con il numero di versione che è il
proprio. Serve quello completo: l'Utilità di pianificazione non conosce il `php` del
terminale di Laragon.

**L'operazione**, da un prompt dei comandi *come amministratore*, in una riga sola (inviti,
ogni quarto d'ora):

```
schtasks /create /tn "Pistacchio - inviti" /sc minute /mo 15 /ru "%USERNAME%" /tr "cmd /c cd /d C:\laragon\www\lms && \"C:\laragon\bin\php\php-8.4.3-Win32-vs17-x64\php.exe\" bin\invita-utenti >> storage\logs\inviti.log 2>&1"
```

Per il rilascio dei moduli è identica, con `/sc daily /st 07:30` al posto di `/sc minute /mo 15`.

**`cd /d` non è decorativo**: i comandi cercano `vendor/autoload.php` e il `.env` relativi
alla cartella corrente, e senza quello partono e non trovano niente. Se `storage\logs` non
esiste va creata prima, altrimenti il log non si scrive e non lo si sa.

**Provarla subito, senza aspettare:**

```
schtasks /run /tn "Pistacchio - inviti"
type C:\laragon\www\lms\storage\logs\inviti.log
```

Nel log compare `inviti mandati: 0 / in coda ne restano: 0`. Se il file non esiste
l'operazione non è partita: `schtasks /query /tn "Pistacchio - inviti" /v /fo list` mostra
«Ultimo risultato», dove `0` vuol dire andata bene.

Con l'interfaccia grafica: Crea attività di base, attivazione «Ogni giorno», poi proprietà →
Attivatori → Modifica → «Ripeti attività ogni» 15 minuti, durata «Indefinitamente».

**Finché il computer è spento gli inviti non partono**, ma non si perdono: restano in coda e
partono al primo giro utile, oppure a mano dalla pagina Utenti.

**Questa pianificazione vive dentro Windows e non si porta dietro niente**: non sta nel
repository, nessun deploy la copia. Sull'hosting Linux vanno rifatte da zero tutte e due come
righe di cron, ed è nell'elenco delle cose da fare prima di aprire agli studenti.

## Il benvenuto del tutor nel corso

In cima alla pagina di un corso lo studente trova **il benvenuto del tutor del suo gruppo**: una
foto a mezzo busto con un breve audio subito sotto, e accanto il nome, **l'email del tutor**,
**il link al gruppo WhatsApp** e il testo di quello che il tutor dice («Leggi il testo»). È diverso dal video di benvenuto qui sotto: quello è della
piattaforma e si vede al primo accesso, questo è del tutor e sta in ogni corso.

- **Uno per tutor e per corso.** Un corso seguito da più gruppi con tutor diversi ha più
  benvenuti, e ogni studente sente quello del proprio tutor. **Chi non è in un gruppo con un
  tutor** (iscritto dal catalogo) **non vede niente**. Uno studente in due gruppi dello stesso
  corso, con due tutor che hanno entrambi un benvenuto, sente quello del tutor che viene prima
  per nome: un ordine fisso invece di uno che cambia fra le visite.
- **Completo, poi ridotto a una riga.** Completo nelle prime tre visite alla pagina del corso;
  dalla quarta, **o appena l'audio è stato ascoltato fino in fondo** (la prima delle due cose),
  diventa una riga con la miniatura, il nome e «Mostra», che riapre la scheda; da aperta il
  comando dice «Nascondi» e la richiude. Le due parole le scambia lo stile. La riga è un
  `details`, quindi si apre senza JavaScript; lo script (`benvenuto.js`) serve solo a dire al
  server che l'audio è finito. Senza, vale il conto delle visite.
- **Lo carica il tutor**, il proprio, dalla pagina di modifica dei corsi dei suoi gruppi
  (sezione «Il tuo benvenuto», permesso `course.welcome_own`). L'admin lo carica per qualunque
  tutor (sezione «Benvenuto dei tutor», un blocco per ogni tutor del corso, permesso
  `course.welcome`). È lo schema di `group.manage` e `group.manage_own`. **Ogni corso deve
  averne uno**: è una regola di chi organizza i corsi, e la sezione segnala quando manca.
- **I contatti stanno solo dentro il benvenuto**, e nella riga ridotta non si vedono: si vedono
  riaprendola con **«Mostra»**. Ciascuno compare solo se c'è:
  - **l'email per gli studenti** è un campo del profilo del tutor, «Email per gli studenti»,
    diverso dall'email con cui accede, che non viene mai mostrata;
  - **il link WhatsApp** è un campo della pagina del gruppo, «Link di invito in WhatsApp», che
    impostano l'admin e il tutor del gruppo. Si accettano solo inviti di WhatsApp
    (`https://chat.whatsapp.com/…`). Lo studente vede il link del proprio gruppo, quello con
    quel tutor in quel corso, e si apre in una scheda nuova. Il link è una chiave d'accesso al
    gruppo WhatsApp: non compare in nessun'altra pagina.
  Le icone sono disegni generici, una busta e un fumetto, non il logo di WhatsApp.
- **Il testo è obbligatorio**: senza, l'audio non è accessibile a chi non sente o non può
  ascoltare in quel momento (WCAG 1.2.1).
- **I file stanno in `storage/welcomes/`**, fuori dal repository, e si servono da
  `/benvenuti/{id}/foto` e `/benvenuti/{id}/audio` solo all'admin, al tutor del benvenuto e ai
  suoi studenti in quel corso; agli altri rispondono 404, come un benvenuto che non c'è. L'audio
  si consegna anche a pezzi (`Range`, `App\Core\FileStream`), che Safari pretende.
- **L'audio è breve**: MP3 o M4A fino a 5 MB. Servito da PHP occupa un processo mentre si
  scarica, e per pochi secondi va bene; un audio di mezz'ora no (lo stesso ragionamento dei
  video, che stanno su Bunny). La foto, JPG, PNG o WebP fino a 8 MB, si salva ridotta a 900 px
  sul lato lungo e ricodificata in JPEG, che toglie anche i dati nascosti dello scatto.
- **Sostituire un file cancella quello vecchio**, come per la copertina del corso; «Rimuovi
  benvenuto» toglie la riga e i due file.

**La testa della pagina del corso** è una colonna sola (scelta su due mockup il 07/10): titolo,
eventuali conferme, **copertina a fascia** (16:5, larga quanto i moduli: l'immagine caricata in
16:9 mostra la fascia centrale), descrizione, benvenuto, poi certificato e moduli. **Copertina,
benvenuto e moduli hanno gli stessi bordi**; prima erano 569, 900 e 1016 px allineati solo a
sinistra. La descrizione resta a 62 caratteri di riga, perché è un testo da leggere e non un
riquadro. `accessibilita.js` controlla che i tre blocchi abbiano gli stessi bordi.

**I nomi delle classi della scheda cominciano con `tutor-benvenuto`.** Nella prima versione la
scheda si chiamava `.benvenuto`, che era già la classe della pagina del video di benvenuto: le
due si prendevano lo stile a vicenda, e la pagina del video, che deve essere un blocco centrato,
diventava un riquadro con il bordo allineato a sinistra.

## Il primo accesso

Al primo accesso lo studente arriva sulla pagina **«Primo accesso»** (`/primo-accesso`, 08/10,
chiesto da Elena): una pagina sola, nell'aspetto delle pagine di accesso e senza menu, con
quello che serve davvero.

- **«Ciao, Marta»**, senza genere, come il saluto dopo l'accesso.
- **Chi ha ricevuto le credenziali per email** trova la password ricevuta, la nuova password e
  la sua conferma, e **«Come ti vedono gli altri studenti»**: «Due cose prima di cominciare».
  La password ricevuta si chiede, come in ogni cambio password, perché è ciò che impedisce a chi
  trova una sessione aperta di prendersi l'account.
- **Chi si è registrato da sé** trova solo la scelta: «Una cosa prima di cominciare».
- La scelta parte da **«Solo il nome»** (`PersonName::PRESELEZIONATA`, anche nel profilo). È la
  proposta di chi sceglie; finché non sceglie, gli altri lo vedono con le iniziali
  (`PersonName::PREDEFINITO`). Sono due costanti diverse apposta.
- **Il modulo è corto** (08/10): sotto la password «Minimo 8 caratteri: almeno una lettera e un
  numero.» (`PasswordPolicy::HINT_BREVE`, accanto alla frase lunga e alla regola, che il resto
  della piattaforma continua a usare), sotto la scelta «Tutor e admin vedono sempre nome e cognome.
  Scelta modificabile dal profilo.», e le tre opzioni vicine: il pallino
  non prende più l'altezza di un campo (2,5 rem), e il modulo passa da 634 a 547 px.
- Un solo «Continua»: tutto si controlla prima di scrivere, e un errore sulla password non
  salva la scelta a metà. Poi il video di benvenuto, se c'è, e i corsi. «Esci» resta sempre.

**«Primo accesso» vuol dire «non ha ancora scelto»** (`name_display` NULL). È un cancello di
`Auth::guardSession`, nell'ordine: sessione valida → **primo accesso** (che comprende il
cambio password) → password temporanea da cambiare (per chi ha già scelto, per esempio dopo una
password data dall'admin) → video di benvenuto. Il cancello della password e quello del video
lasciano passare chi sta facendo il primo accesso: senza, si rimandavano a vicenda all'infinito
(il secondo caso con il video configurato, trovato da `permessi.js`). Il saluto «Che bello
rivederti» non compare al primo accesso.

## Il saluto dopo l'accesso

A ogni accesso lo studente trova in alto **«Che bello rivederti, Marta! 🌸»**, con il suo nome
di battesimo, per 5 secondi (08/10, chiesto da Elena). Il fiore è decorativo (`aria-hidden`):
un lettore di schermo legge solo la frase. **Una frase senza genere**, scelta di Elena:
Pistacchio non sa se dire «bentornata» o «bentornato», e indovinarlo dal nome sbaglierebbe.

- **Solo agli studenti**, e non quando stanno per vedere il video di benvenuto né quando c'è una
  password temporanea da cambiare. «Stanno per vedere il video» si chiede come il cancello del
  video: non visto **e** configurato. Senza video configurato il saluto lo ricevono tutti. Lo decide `AuthController::login`, che annota
  il nome in sessione; lo mostra una volta sola la prima pagina (`partials/shell.php`).
- **Entra con calma** (08/10, Elena: «appare un po' bruscamente»): aspetta 0,2 secondi che la
  pagina si sia disegnata, poi scende dall'alto in 0,9 secondi con un accenno di ingrandimento e
  una curva che rallenta all'arrivo.
- **Compare e sparisce con un'animazione di solo stile**, senza JavaScript; alla fine resta
  `visibility: hidden`, quindi sparisce anche per i lettori di schermo, che lo leggono una
  volta (`role="status"`). Con «riduci movimento» non scivola.
- **Si adatta al nome**: è largo quanto il testo, fino allo schermo meno i margini, e un nome
  lunghissimo va a capo. Sul telefono sta sotto la fascia del menu. Non si clicca e non
  impedisce di toccare quello che c'è sotto.

## Domande e risposte

In fondo a ogni corso c'è **«Domande e risposte»** (07/10, chiesto da Elena). **Parte ripiegata
in una riga** (08/10): il titolo, il numero delle domande pubblicate e «Mostra», che la apre e
diventa «Nascondi». È un `details`, senza JavaScript. **Si apre da sola** dopo una ricerca, dopo
l'invio di una domanda e dopo un invio respinto; alla visita successiva riparte chiusa. Il comando
ha sempre lo spazio della parola più lunga, così la riga non cambia forma aprendola.

**Aperta, si legge come due moduli in più del corso** (08/10, scelta di Elena su un mockup). Il
primo riquadro è la sezione stessa: la riga del titolo è la sua testata, sotto c'è l'archivio
diviso per modulo e, **in fondo, la ricerca**. Il secondo, staccato di 1,5 rem, è **«Fai una
domanda al tutor» con «Le tue domande»**: sta fuori dal `details` e lo stile lo nasconde quando
la sezione è chiusa. **Le misure sono quelle dei moduli**: titoli 1,05 rem, domande, risposte e
testi 0,9 rem come le lezioni, i dettagli 0,8 rem; una domanda aperta passa in grassetto.

- **Lo studente fa una domanda al tutor** su un modulo del corso o sul corso in generale, e
  vede le sue con lo stato: in attesa, pubblicata, non pubblicata. Se c'è un errore il testo
  resta nel campo. **La domanda è lunga al massimo 1.000 caratteri**, con il contatore sopra
  l'angolo in alto a destra del campo («1000 caratteri rimasti», a scalare): lo stesso della
  presentazione del profilo e delle risposte aperte, con lo stesso script. Il limite vale anche
  senza JavaScript (`maxlength`) e sul server.
- **La riceve il tutor del suo gruppo**, con **un'email per ogni domanda nuova** (corso,
  modulo, nome, testo intero); se lo studente non ha un tutor, l'email va agli amministratori.
  Lo studente non riceve avvisi: trova la risposta tornando al corso.
- **Il tutor e l'admin** rispondono dalla pagina **«Domande»**, una voce della barra laterale
  con il numero di quelle in attesa. Il tutor vede quelle assegnate a lui, l'admin tutte, e per
  ognuna **il tutor a cui è assegnata**. Prima di pubblicare si possono correggere il testo,
  per esempio per togliere dettagli personali, e il modulo. **Ogni risposta si pubblica**:
  non ci sono risposte private. Una domanda doppia, fuori tema o troppo personale si
  **scarta**, e lo studente la vede «Non pubblicata».
- **L'archivio lo vedono tutti gli iscritti al corso, di ogni gruppo**, diviso per modulo
  nell'ordine del corso con «Il corso in generale» in fondo, ciascuna domanda in un `details`.
  L'autore compare **come ha scelto nel profilo** (`PersonName::shown()`); lo staff lo vede
  per intero. **La ricerca** cerca nelle domande e nelle risposte, senza JavaScript
  (`?cerca=` sulla pagina del corso); `%` e `_` si cercano come testo.
- **Correggere e togliere** (08/10). Nella pagina «Domande», sotto quelle in attesa, la parte
  **«Pubblicate»**, divisa per corso: ogni domanda si apre per correggere domanda, modulo e
  risposta («Salva»), o per **toglierla dall'archivio**. Tolta, torna «Non pubblicata», con la
  risposta e chi l'aveva data, e lo studente la vede ancora fra le sue con quello stato. Nell'archivio
  del corso, **chi può gestire una domanda vede «Modifica»**, che porta lì con la domanda già
  aperta (`/domande?apri=`). La regola di chi può è una sola, `QuestionController::puoGestire()`:
  l'admin tutte, il tutor quelle assegnate a lui.
- **Niente risposte fra studenti, commenti o voti**: è la decisione di non fare messaggistica
  fra studenti, e in mezzo c'è sempre il tutor.

Le regole stanno in `App\Models\QuestionModel`, i permessi nel controller: `question.answer`
(admin, tutte) e `question.answer_own` (tutor, le sue). «Scarta» è un modulo a sé, nella riga di
«Pubblica» con l'attributo `form`: dentro il modulo di pubblicazione diventerebbe il suo invio
predefinito. `tests/domande_test.php` prova assegnazione, attesa, pubblicazione e archivio;
`permessi.js` chi può fare che cosa, l'archivio e l'email al tutor.

## Video di benvenuto

Uno studente che accede per la **prima volta** vede una pagina con un video, un pulsante
«Vai ai miei corsi», e nient'altro. Una volta sola: il pulsante registra la visione in
`users.welcome_seen_at` e da lì in avanti si atterra su «I miei corsi» come sempre. Dal
profilo c'è un collegamento «Rivedi video di benvenuto», perché una pagina che si vede una
volta sola è una pagina che nessuno può rivedere, e chi la chiude per sbaglio avrebbe perso
quello che c'era dentro.

Il video lo imposta l'admin in **Impostazioni → Video di benvenuto**: provider (Bunny o
Cloudflare) e identificativo, come nelle lezioni. Niente `self_hosted`, che viene servito
passando dall'identificativo di una lezione e il benvenuto non è una lezione. **Senza video
configurato la pagina non esiste e nessuno viene dirottato**: il benvenuto si accende
mettendo il video, non con un interruttore a parte che si può dimenticare acceso a vuoto.

Quattro decisioni che non sono evidenti dal codice:

- **Chi era già iscritto non lo vede.** La migrazione riempie `welcome_seen_at` per tutti gli
  utenti esistenti al momento in cui viene applicata: chi è dentro da prima non viene
  interrotto da una schermata nuova. Lo vedranno solo gli account creati da lì in avanti. Per
  farlo rivedere a una persona bisogna svuotargli la casella a mano.
- **Dopo il cambio password, non prima.** Chi entra con una password temporanea deve prima
  sceglierne una sua: il controllo sta in `Auth::guardSession()` subito **sotto** a quello del
  cambio password, altrimenti due schermate obbligate si contendono la stessa persona.
- **Solo gli studenti.** Lo staff entra per lavorare, e un video di benvenuto davanti
  all'amministratore che deve sistemare un corso è un ostacolo, non un'accoglienza.
- **Il pulsante non dipende dall'aver guardato.** Se Bunny non risponde o il player non parte,
  si prosegue lo stesso: il peggio che può succedere dev'essere il comportamento di sempre.

Non costa una query in più: `welcome_seen_at` viaggia nella stessa query che
`guardSession()` fa già a ogni richiesta per lo stato della password.

**Le pagine esenti dal rimando sono un prefisso, non un indirizzo.** Sotto `/benvenuto` c'è
anche la POST che registra la visione: trattandola come «non esente» la si dirotta verso la
pagina del benvenuto, e il giro non si chiude mai. Trovato provando, non leggendo.

## Agenda

`/agenda` risponde a una domanda sola: **che cosa mi aspetta**. Dentro ci sono due tipi di
evento — gli incontri dal vivo dei propri corsi e gruppi, e le date in cui si aprono i moduli
a rilascio programmato — distinti da un'etichetta scritta oltre che da un colore. Le due
etichette («Incontro dal vivo», «Apertura di un modulo») stanno in `Agenda::etichettaTipo()`
e non nella vista: compaiono in due punti — sotto al titolo nell'elenco e nel testo che un
lettore di schermo annuncia nella griglia del mese — e scritte due volte divergono.

**Due viste, un indirizzo** (`/agenda?vista=mese`), così il collegamento che si manda a
qualcuno porta la vista che si stava guardando.

- **Elenco** (la vista d'ingresso): «Oggi», «Nei prossimi sette giorni», «Pianificato», e in
  fondo i passati, richiusi. Sette giorni e non «fino a domenica»: di domenica pomeriggio il
  secondo criterio lascerebbe vuoto proprio il gruppo che interessa. Un incontro cominciato
  ma non finito resta fra quelli di oggi — è il momento in cui serve di più — e lì compare il
  pulsante «Entra», che appare solo da un quarto d'ora prima dell'inizio fino alla fine.
- **Mese**: griglia che comincia di lunedì, con i giorni di orlo in grigio. **Il giorno
  corrente si riconosce dalla forma, non dal colore**: cornice nel colore principale e numero
  dentro una pastiglia tonda piena, senza riempire la cella. Prima la cella usava
  `--color-primary-soft`, cioè lo stesso valore dello sfondo delle pastiglie degli incontri:
  oggi si leggeva come un incontro largo quanto il giorno. Nessuna tinta tenue alternativa
  regge — misurate, distano 6-11 dai `soft` delle quattro tavolozze, contro i 28-32 che
  separano i `soft` dal bianco — e una quinta tinta andrebbe riverificata contro ogni
  tavolozza più il colore principale libero. `accessibilita.js` ora verifica, sui colori
  calcolati, che la cella di oggi non abbia lo sfondo di una pastiglia. Sotto alla
  griglia c'è la **legenda** dei due colori, e passando il mouse su una pastiglia il tipo
  compare come suggerimento del browser (`title`). La legenda non è decorazione: senza, il
  colore sarebbe l'unico modo di distinguere i due tipi, che è quello che il criterio 1.4.1
  delle WCAG chiede di non fare. Il suggerimento del mouse non si vede da tastiera né col
  dito, e infatti non è lui a reggere la distinzione: ci sono la legenda e il testo nascosto
  dentro a ogni pastiglia, che un lettore di schermo annuncia. Sotto i 36 rem di
  spazio diventa l'elenco dei soli giorni che hanno qualcosa: sette colonne in 320 px fanno
  caselle da 40 px, dove un titolo non ci sta e un bersaglio da toccare nemmeno.

Settimana e giorno non ci sono, ed è una scelta: senza orari fitti mostrerebbero le stesse
due righe dell'elenco occupando uno schermo intero. Si aggiungono il giorno che gli incontri
saranno molti — la forma degli eventi in `App\Core\Agenda` è già quella giusta, e un terzo
tipo di evento (una scadenza dei questionari, che oggi non esiste) si aggiunge in un posto solo e
compare in tutte e due le viste e nel calendario esterno.

**Chi vede cosa** non si decide qui: la regola è una sola, in `App\Core\LiveScope`, e la usano
la pagina «Sessioni live», l'agenda e il calendario esterno. Prima stava dentro al controller
delle sessioni come metodi privati, e andava bene finché gli incontri si guardavano da una
pagina sola.

### Il calendario nel proprio programma

Due modi, dalla stessa pagina:

- **un incontro alla volta**: `/agenda/evento/{id}.ics`, che chiede l'accesso come ogni altra
  pagina e risponde 404 per un incontro che non è fra i propri. Il collegamento «Aggiungi
  al calendario» compare **finché l'incontro non è finito**: nello Storico non c'è, perché
  mettere in agenda un appuntamento già passato non serve a niente;
- **tutta l'agenda, sempre aggiornata**: un indirizzo personale `/calendario/{token}.ics` da
  incollare in Google Calendar, Calendario di Apple o Outlook.

Accanto all'indirizzo la pagina mostra **da quando esiste e quando è stato letto l'ultima
volta**: è quello su cui si decide se tenerlo. Un calendario che non risulta letto da mesi
si disattiva, e una lettura che non si spiega è il motivo per cui il pulsante «Rigenera» sta
lì sotto — rigenerare è una revoca, il link vecchio smette di funzionare subito. Non si
registra **chi** ha letto (niente indirizzi IP, niente nomi di programmi): la data basta a
decidere, il resto sarebbe un registro di abitudini che nessuno ha chiesto.

Il secondo è **l'unico indirizzo interno che risponde senza accesso**, e lo fa perché deve: a
rileggerlo è un programma, ogni tanto, senza nessuno davanti che possa scrivere una password.
Al posto dell'accesso c'è il token, che quindi **è una credenziale**: 24 byte dal generatore
crittografico, colonna `users.calendar_token` con un indice unico, NULL finché non lo si
chiede — un segreto che non è mai stato creato non può essere rubato. Si rigenera e si
disattiva dall'agenda, e la pagina dice in chiaro che chi ha quel link vede gli impegni di
quella persona. Un utente disattivato non ha più calendario, anche se il link gli è rimasto
nel telefono.

Il file è un `METHOD:PUBLISH` con un `VEVENT` per evento, diverso dall'invito che parte per
email (`METHOD:REQUEST`, con organizzatore e invitato): un calendario sottoscritto elenca,
non invita, e mandare dei REQUEST in un feed vorrebbe dire chiedere a ogni rilettura di
rispondere a un invito già accettato.

## Test

Due famiglie. I test PHP girano da soli; i due file `.js` usano Playwright e hanno bisogno del
server di sviluppo attivo.

```bash
# logica pura, nessun database
php tests/google_meet_test.php      # client Google: JWT firmata, richieste, errori 403/404
php tests/html_sanitizer_test.php   # l'HTML dell'editor non può contenere codice eseguibile
php tests/course_cover_test.php     # ritaglio 16:9, due misure, testo alternativo (serve GD)
php tests/bunny_token_test.php      # firma e scadenza degli indirizzi Bunny
php tests/watch_intervals_test.php  # fusione degli intervalli guardati, tetto di plausibilità
php tests/quiz_scoring_test.php     # punteggio dei quattro tipi di domanda
php tests/importazione_test.php     # 27 prove: codifiche, separatori e righe del file utenti
php tests/inviti_test.php          # coda degli inviti: chi conosce una password non viene sovrascritto
php tests/benvenuto_tutor_test.php # benvenuto del tutor: chi sente quale, completo o ridotto, le visite
php tests/nome_test.php            # nome e cognome: divisione, iniziali, chi vede cosa, importazione
php tests/domande_test.php         # domande: a chi vanno, chi le vede, pubblicare e scartare, archivio e ricerca
php tests/password_test.php         # regola della password e generatore
php tests/lesson_video_test.php     # scelta del provider e dei riferimenti video
php tests/live_session_mail_test.php   # testi delle email degli incontri
php tests/xlsx_test.php             # il file XLSX scritto in casa

# richiedono il database di sviluppo (ci scrivono, e puliscono da soli)
php tests/settings_test.php         # impostazioni in tabella, con il .env come ripiego
php tests/rilascio_test.php         # rilascio progressivo: catena, conti, niente email doppie
php tests/tema_test.php             # tavolozze, arrotondamento, misure del testo, colore del testo
php tests/caratteri_test.php        # catalogo dei caratteri, nome dei file, ripiego manuale, i tre messaggi d'errore
php tests/report_test.php           # tagli dei report: suggerimenti, ricerca, paginazione
php tests/ordinamento_test.php      # ordinamento: confronti, vuoti in fondo, spareggio, indirizzi
php tests/agenda_test.php           # agenda: raggruppamento, griglia del mese, file .ics
php tests/cerchio_test.php          # pagina del gruppo: posizioni nel cerchio, nomi verso l'esterno, soglia dei 20

# richiedono il server attivo:  php -S 127.0.0.1:8123 -t public router-dev.php
#   (`router-dev.php` sta nella radice del repo: il server integrato di PHP non ha
#    `.htaccess`, e senza di lui gli indirizzi dell'applicazione rispondono 404)
node tests/accessibilita.js         # circa 2.750 controlli su 63 pagine, a tre larghezze, un giro senza mouse e uno da studente e da tutor (il numero dipende dai dati)
node tests/permessi.js              # 179 prove: ogni ruolo prova a raggiungere le cose di un altro, più il benvenuto, i gruppi, le foto, le presentazioni e il benvenuto del tutor
node tests/coerenza_moduli.js       # i tre sistemi di moduli disegnano la stessa cosa allo stesso modo
node tests/ordinamento_pagine.js    # ogni colonna ordinabile di ogni pagina, cliccata davvero
```

### I tre controlli automatici che vale la pena conoscere

**Accessibilità** (`accessibilita.js`). Su **tutte** le pagine HTML della piattaforma verifica
lingua e titolo, un solo titolo principale, contrasto del testo, campi con etichetta, immagini
con testo alternativo, comandi con un nome, identificativi non ripetuti, titoli senza salti di
livello, bersagli di almeno 24 px, fuoco visibile e **nessuno scorrimento orizzontale**.
C'è anche **«i pannelli che si aprono non vengono ritagliati»**: apre ogni tendina della
pagina e verifica che il pannello non esca dal primo antenato che scorre. Un contenitore con
`overflow` ritaglia quello che esce, e il difetto compare solo dopo un clic — a pagina chiusa
non lo vedeva nessun controllo.
Il controllo sullo scorrimento orizzontale gira a 390 px, a **320** (il minimo che chiede la 1.4.10 delle WCAG) e a 844×390,
cioè il telefono girato di lato; quando fallisce dice **quale elemento** sfora. È scritto in
Node e non in PHP perché contrasto, fuoco e dimensioni esistono solo dopo che il browser ha
applicato il CSS.

**Sei pagine si aprono sui dati della semina**, non su un id fisso: una lezione con un
materiale, la pagina di fruizione di un video senza durata, una domanda vero/falso, un gruppo
con un corso assegnato e il suo report con un certificato, e la pagina di un gruppo da otto. Le pagine con l'id scritto nel
test (`/lessons/1`, `/questions/1/edit`…) mostrano quello che l'id 1 è nel database su cui si
gira, e con i dati di prova quei casi non c'erano: sette difetti veri sono rimasti invisibili
finché il controllo non è stato fatto girare sul database di sviluppo vero. Per questo
`accessibilita.js` **esegue da sé `semina_permessi.php`**, come `permessi.js`, e ne legge gli
id — quindi anche lui scrive nel database: non va lanciato in produzione.

**Non è un test di usabilità**, ed è scritto per non essere scambiato per tale: dice se una
pagina rispetta delle regole misurabili, non se una persona capisce cosa deve fare.

**Ordinamento** (`ordinamento_pagine.js`). Apre ogni pagina con una tabella ordinabile,
segue **ogni** collegamento di intestazione e verifica quattro cose: che si apra, che sia
quella colonna — e nessun'altra — a dichiararsi ordinata, che le righe restino le stesse
(ordinare rimette in fila: non aggiunge, non toglie, non duplica) e che su una colonna di
testo l'ordine alfabetico ci sia davvero. Quest'ultima è la sola che vede l'errore più
insidioso: un'intestazione legata per sbaglio al campo di un'altra colonna ordina
benissimo, e la pagina resta piena di valori plausibili nell'ordine sbagliato. Provato che
sa diventare rosso legando «Email» al campo del nome.

**Permessi** (`permessi.js`). `semina_permessi.php` crea **due mondi paralleli e simmetrici**,
A e B, con proprietari diversi — un controllo sui permessi ha senso solo se c'è qualcosa che
non si deve poter toccare. Poi, per ogni ruolo, si prova a raggiungere le cose dell'altro
mondo **scrivendo l'indirizzo a mano**, dichiarando in anticipo che cosa ci si aspetta. Si
provano anche le POST distruttive, perché metà del danno possibile sta lì.

Guarda la porta, non che cosa c'è nella stanza: una pagina può rispondere 200
legittimamente e mostrare dentro righe che non dovrebbe. Per l'elenco degli studenti —
l'unica pagina dei report che si apre per tutti e tre i ruoli dello staff mostrando righe
**diverse** a ciascuno — il controllo guarda anche dentro: l'assistente non deve trovarci lo
studente di un altro tutor, nemmeno cercandolo per email, e l'amministratore invece sì
(senza la controprova, "non lo trova" non dimostrerebbe niente).

> **Un verde è sospetto finché non lo si è visto diventare rosso.** Rompendo di proposito
> una regola, i controlli che la riguardano devono fallire. È così che è saltato fuori che
> una prova sul download di un materiale passava per il motivo sbagliato — il file non
> esisteva sul disco, e il 404 del file mancante veniva scambiato per un rifiuto del permesso.

`tests/semina_permessi.php` **scrive nel database dell'installazione su cui gira**: è per lo
sviluppo, non per la produzione. Lo eseguono da sé anche `permessi.js` e `accessibilita.js`.
Oltre ai due mondi semina, nel mondo A, i casi che servono ai controlli di accessibilità:
una domanda vero/falso, un materiale su una lezione aperta e un certificato emesso. Mette
anche una foto vera sul disco allo studente di A, a quello di B e al tutor di A, e un gruppo
«cerchio» con il tutor di A e otto partecipanti, sette dei quali sono utenti
`compagno1@test.it`…`compagno7@test.it` che restano fra una semina e l'altra.

## File caricati: la piattaforma non cancella mai da sola

**Nessun file caricato viene eliminato automaticamente.** Né sostituendo un video, né cambiando
provider, né eliminando una lezione: restano tutti sul server, e si tolgono solo con un comando
esplicito. La scelta è voluta — un file cancellato non si recupera — e il prezzo è che i file
scollegati vanno tenuti d'occhio.

Sotto il pulsante Salva, quando la lezione ha un video caricato, ci sono due comandi:

- **Rimuovi dalla lezione**: toglie il riferimento, il file resta sul server;
- **Rimuovi dalla lezione e dal server**: toglie il riferimento e cancella il file, con conferma.

Sono moduli a sé e non pulsanti dentro quello principale: lì sarebbero stati il primo pulsante
di invio, e premere Invio in un campo di testo avrebbe tolto il video invece di salvare.

Quando un video smette di essere collegato per altra via — sostituendone uno, o cambiando
provider — compare un avviso che dice dov'è rimasto il file. Eliminando una lezione, la
conferma avverte che i file restano, e il messaggio finale dice quanti sono e quanto occupano.

Il file video viene preso in considerazione **solo** se la tendina del provider è su
"Self-hosted": sceglierlo con la tendina su altro non caricava niente e non diceva niente, ora
compare un avviso. I campi dei provider non scelti ora spariscono davvero: la regola CSS
`display: flex` vinceva sull'attributo `hidden`, così il campo "carica file video" si vedeva
anche con la tendina su "Nessuno".

### Dove si vedono i file scollegati

- **In fondo a Gestione corsi**: riepilogo di tutta la piattaforma, per cartella, con numero di
  file e spazio occupato (`App\Core\OrphanFiles::summary()`).
- **Nella pagina di modifica della lezione**: una riga con i soli file di quella lezione, che
  costa una lettura di tre cartelle invece di tutte.

Si contano confrontando il disco con i riferimenti nel database: `lessons.video_ref`,
`lesson_materials.file_path` e — per le immagini, che non hanno una tabella — i nomi citati
dentro `content_html`. Vanno cancellati a mano: nessuna pagina può sapere se servono ancora.

## Limiti di caricamento (video e materiali)

La piattaforma accetta video fino a 500 MB, ma **il limite vero lo impone PHP**: valgono
`upload_max_filesize` e `post_max_size` nel `php.ini`, e basta superarne uno. Su Laragon i
valori predefiniti sono bassi, quindi per caricare video vanno alzati entrambi (più
`max_execution_time` e `max_input_time`, se la connessione è lenta) e Apache va riavviato.

Due modi diversi di fallire, ora distinti:

- file oltre `upload_max_filesize` ma richiesta sotto `post_max_size`: PHP consegna la
  richiesta con un codice d'errore, e il messaggio dice qual è il limite attuale;
- richiesta oltre `post_max_size`: PHP **scarta l'intero corpo** prima che il codice parta,
  quindi spariscono anche `$_POST` e il token CSRF. Senza un controllo apposta il router
  risponderebbe «Sessione scaduta o richiesta non valida», mandando a cercare un problema di
  login dove c'è solo un file troppo grande. Il Router riconosce il caso (corpo vuoto ma
  `CONTENT_LENGTH` maggiore di zero) e risponde 413 dicendo il limite.

Il modulo della lezione annuncia il limite effettivo, cioè il più basso fra quello della
piattaforma e quello del `php.ini`.

## Incontri dal vivo nella pagina della lezione

Sotto il video, la lezione mostra gli incontri **del proprio modulo** ancora da fare o in
corso; quelli passati non compaiono. Il pulsante "Entra nella riunione" si attiva da un quarto
d'ora prima dell'inizio fino alla fine, e prima di allora resta la sola data. Chi non ha ancora
il link Meet vede scritto che non è disponibile.

**La finestra d'ingresso è una sola per tutta la piattaforma.** Le quattro pagine che mostrano
«Entra» — la lezione, l'agenda, l'elenco degli incontri e il dettaglio della sessione — leggono
`joinable` e `started` da `LiveSessionModel::FINESTRA_SELECT`, cioè dalla query. Prima erano
tre regole diverse: la lezione la calcolava in SQL, l'agenda la rifaceva in PHP, e le altre due
mostravano il comando per qualunque incontro non ancora concluso — anche fra tre settimane.
Fuori dalla finestra non compare un comando spento ma la frase che spiega quando: un
collegamento senza `href` non prende il fuoco col tabulatore e un lettore di schermo non lo
annuncia, e il grigio da solo non dice perché. Il calcolo sta in SQL perché server e database
possono trovarsi su fusi diversi.

Nell'elenco «Sessioni live» questo sta in una colonna sua, **Accesso**, che dice sempre come
si entra e cambia contenuto invece di apparire e sparire: «Da 15 minuti prima» finché è
presto, poi il collegamento «Entra», niente a incontro concluso. Nella pagina della lezione e
nel dettaglio della sessione, dove non c'è una tabella, resta la frase «Si entra da 15 minuti
prima».

Le finestre temporali sono calcolate in SQL (`LiveSessionModel::upcomingForModule`), non in
PHP: server web e database possono trovarsi su fusi diversi.

Il link è un `<a>` normale, senza `target`: la riunione si apre nella stessa scheda, su
qualunque dispositivo, così il tasto Indietro riporta alla lezione. Da una scheda nuova si
tornerebbe indietro solo dal selettore delle schede, scomodo su telefono. Se il telefono
dirotta il link sull'app Meet, la lezione resta dov'era nel browser. Niente JavaScript: un solo
comportamento da spiegare e da provare.

Google Meet **non si può incorporare** in un iframe dentro la piattaforma: `meet.google.com`
vieta di essere incorniciato da altri siti e il browser rifiuta di disegnarlo. Per avere la
videoconferenza dentro la pagina servirebbe un provider nato per essere incorporato (Jitsi,
Whereby, Daily), cioè lasciare Meet.

## Colori: le tavolozze

**Amministrazione → Aspetto → Colori.** Quattro tavolozze pronte — verde pistacchio
(predefinita), blu ardesia, terracotta, prugna — più un campo facoltativo per il solo colore
principale. **I colori valgono su tutta la piattaforma**, dentro e fuori; struttura e testi
delle pagine pubbliche, nella stessa pagina, riguardano invece solo quelle cinque pagine.

**`style.css` non viene mai riscritto.** I colori sono già variabili CSS, e la tavolozza
scelta diventa un blocco `:root { … }` stampato **dopo** il foglio di stile: l'ultima
dichiarazione vince. Così una patch futura non trova conflitti su `style.css` e `git am` non
si blocca — era la condizione posta in §8.5 del promemoria. Con la tavolozza predefinita e
nessun colore personalizzato non si stampa niente: sarebbe un blocco identico a quello che
sovrascrive.

**Perché tavolozze pronte e non un campo per colore.** Una tavolozza sono sette valori che
devono reggersi fra loro: il colore principale deve staccare sul bianco *e* sul fondo delle
pagine pubbliche, il bianco deve leggersi sopra di esso, la variante scura sopra la propria
tinta chiara. Sette campi liberi vogliono dire combinazioni illeggibili. Ogni tavolozza è
verificata su **dieci coppie** prima di essere offerta, e `tests/tema_test.php` rifà quel
conto a ogni esecuzione.

L'eccezione è il **colore principale**, che è quello che si vuole cambiare più spesso, di
solito per avvicinarlo a un marchio. Lì il controllo è al salvataggio: un colore sotto 4,5:1
viene rifiutato dicendo di quanto manca, invece di essere salvato e scoperto dopo. Le varianti
(stato premuto, tinta chiara) si ricavano da quello scelto, e anche loro sono verificate.

> **Un colore arriva dal database e finisce dentro un tag `<style>`.** Senza controllo, un
> valore come `#fff; } body { display:none` sarebbe CSS eseguito. Per questo ogni valore passa
> da `Theme::coloreValido()`, che accetta solo `#rgb` e `#rrggbb` e rifiuta tutto il resto —
> nomi di colore, `rgb()`, `url()`. Stesso principio del sanificatore dell'HTML dell'editor.

La pagina dell'installer è l'unica che non riceve il blocco: gira prima che esistano le
impostazioni.

### Colore del testo

Campo facoltativo, accanto a quello del colore principale. **Il grigio dei testi secondari
non si imposta**: viene ricavato dal primo, schiarito per gradi verso lo sfondo e fermato
all'ultimo passo che resta leggibile. Due campi che devono stare in rapporto fra loro sono
due modi di sbagliare invece di uno.

Il controllo al salvataggio guarda tutti i fondi su cui il testo finisce: il bianco dei
riquadri, lo sfondo delle pagine, e la tinta tenue di **tutte e quattro** le tavolozze —
anche quelle non attive, perché l'admin può cambiare tavolozza dopo aver scelto il colore, e
un colore valido solo con quella di oggi diventerebbe illeggibile domani senza che nessuno
glielo dica.

### Misura del titolo della presentazione

Nel riquadro *Testi della presentazione*, perché riguarda quelle due righe e non altro:
quattro livelli (piccola, normale, grande, molto grande) per il titolo e il testo della
sezione di sinistra dell'aspetto affiancato. Titolo e testo crescono insieme, per non
rompere il rapporto fra i due, e su schermo stretto si riducono **in proporzione alla misura
scelta** invece che a un valore fisso — altrimenti da telefono la scelta non conterebbe
niente.

È separata dalla «Dimensione del testo» qui sotto: lì il titolo è un elemento grafico, e
ingrandirlo è una scelta di presentazione, non di leggibilità. Senza questa misura, per avere
un titolo più grande bisognava ingrandire anche i menu e i report.

> Attenzione al verso opposto: i valori sono in `rem`, quindi alzando **anche** la dimensione
> generale del testo la presentazione cresce insieme al resto. È voluto — un titolo rimasto
> indietro dentro un'interfaccia cresciuta sarebbe sbagliato — ma vuol dire che le due scelte
> si sommano.

### Arrotondamento degli angoli

Quattro livelli: squadrato (0/0), leggero (3/5), normale (6/10, quello di fabbrica), morbido
(10/16). **Due valori per livello**, non uno: nel foglio di stile il raggio piccolo veste
campi e pulsanti, quello grande i riquadri, e il rapporto fra i due è ciò che fa sembrare la
pagina disegnata invece che assemblata. Un campo numerico libero lascerebbe scegliere 2 e 40.

### Dimensione del testo

Quattro misure: compatto 15 px, normale 16, comodo 17, grande 18. **Scala tutta
l'interfaccia, non solo le lettere**: nel foglio di stile quasi ogni misura è in `rem`,
quindi crescono insieme testo, riempimenti e spazi e le proporzioni restano quelle. È il
motivo per cui non esiste un "ingrandisci solo il testo", che lascerebbe lettere grandi
dentro riquadri rimasti piccoli. Sotto i 15 px non si scende.

Resta indipendente dall'ingrandimento del browser: chi alza il testo dalle impostazioni del
proprio browser continua a vederlo crescere, qualunque misura sia scelta qui.

> Offrire le due misure più grandi ha fatto emergere un difetto vero: a 17 e 18 px la riga
> dei comandi di un modulo non entrava più in 320 px e spingeva fuori la pagina di 30 px.
> Ora va a capo — il che è la cosa giusta anche a 16 px, dove semplicemente non capitava.
> È il genere di cosa che si vede solo misurando: l'accessibilità è stata eseguita una volta
> per ogni misura e una per ogni livello di arrotondamento.

## I moduli da compilare: una misura sola

Nel progetto convivono **tre famiglie di moduli**, nate in momenti diversi: `.form` nel
pannello, `.stacked-form` nei moduli brevi, `.auth-form` nelle pagine pubbliche. Ognuna si
era portata dietro la propria misura, e al 04/10 la deriva era questa: etichette a 15,2 px in
due sistemi e **13,1 nel terzo**, campi alti 40 px in due e **34 nel terzo**, e lo stesso
pulsante alto **35 px da solo e 37 accanto a un altro comando**, perché `.form-actions` è una
riga flessibile che lo stirava. Nessuna di queste era una decisione: erano copie invecchiate
in modo diverso.

Ora le dichiarazioni comuni — dimensione e peso delle etichette, aspetto e altezza dei campi
— stanno **in un blocco solo** che elenca i tre sistemi, e nei blocchi dei singoli restano le
differenze vere: la larghezza massima di `.form`, i margini, la disposizione. Chi aggiunge un
quarto sistema lo aggiunge a quei selettori invece di ricopiare i valori: è ricopiandoli che
sono diventati diversi.

I pulsanti hanno un'altezza minima pari a quella dei campi, così uno stesso comando è alto
uguale ovunque e non dipende da chi gli sta accanto. `.btn` è `inline-flex` e non
`inline-block` proprio per questo: con un'altezza minima il testo va centrato nello spazio
che avanza. `.btn-small` conserva la propria altezza, che è la misura minima di un bersaglio
toccabile.

`node tests/coerenza_moduli.js` misura le tre famiglie su sette pagine e fallisce quando una
si discosta. Non è un doppione del controllo di accessibilità: **una pagina con le etichette
piccole è accessibile lo stesso**, e infatti quella deriva è passata sotto i suoi 1225
controlli per giorni. Questo guarda un'altra cosa — che la stessa cosa sia disegnata allo
stesso modo — e non vede se le misure sono *giuste*: tre moduli sbagliati allo stesso modo
passano.

> La tolleranza è di 1,5 px, e non è indulgenza: le pagine pubbliche usano Albert Sans e
> quelle interne il carattere di sistema, e due caratteri diversi alla stessa dimensione
> danno righe alte 17 e 18 px. È una differenza del carattere, non del foglio di stile.

## Ritocchi ai singoli elementi

**Amministrazione → Aspetto → Solo le pagine pubbliche → Ritocchi ai singoli elementi.** Sei
elementi — titolo e testo della presentazione, nome della piattaforma, titolo del modulo,
riga sotto al titolo, pulsante principale — ognuno con le stesse sette possibilità: peso,
stile, lettere, spaziatura, allineamento, colore, e la misura dove già esisteva.

**Perché un meccanismo e non altri comandi.** Fino alla 0084 ogni richiesta diventava un
comando suo. Funziona per quattro, non per quaranta: la pagina cresce finché non si legge
più. Qui c'è un elenco di elementi e un elenco di proprietà, e ogni elemento si regola con le
stesse. **Aggiungere domani «il titolo della lezione» costa una riga in `ElementStyle::ELEMENTI`,
non un riquadro nuovo.**

Cosa lo rende diverso dal «CSS libero», che §8.5 ha escluso:

- **i selettori li scriviamo noi** e stanno nel codice: dal pannello non si sceglie *dove*
  applicare qualcosa, solo *che cosa* applicare a un elemento già nominato;
- **i valori vengono da elenchi chiusi**: non si scrive `font-weight`, si sceglie «Normale».
  Un valore fuori elenco viene ignorato, non stampato — e la pulizia si applica **sia in
  scrittura sia in lettura**, perché il valore passa dal database;
- **nessuna proprietà può nascondere niente.** Niente `display`, `visibility`, `position`: è
  la trappola di §5 — ciò che si nasconde resta inviato — e non deve poter rientrare da una
  tendina. Un test lo verifica sull'elenco delle proprietà, non sulle intenzioni.

Il prezzo è quello che §8.5 chiamava **un impegno**: questi selettori diventano un contratto.
Chi rinomina `.scene-claim h2` deve aggiornare anche quella tabella, o un ritocco salvato
smetterà di avere effetto restando salvato — il difetto peggiore, perché non si vede.

Nel pannello i sei elementi stanno in un riquadro solo, ciascuno in un `<details>`: sei righe
chiuse invece di quarantadue comandi in fila. Si apre da sé quello che è già stato modificato,
e porta l'etichetta «modificato», così chi torna sulla pagina vede dove ha messo le mani senza
aprire sei cassetti. `<details>` è un elemento del browser: funziona senza JavaScript, con la
tastiera e con un lettore di schermo senza che dobbiamo costruirne il comportamento.

> **Una differenza voluta non è una deriva.** Da qui in avanti l'admin può dare di proposito
> al pulsante delle pagine pubbliche un peso diverso da quello interno, e
> `tests/coerenza_moduli.js` fallirebbe su una sua scelta. Il controllo legge ora le regole
> che Pistacchio stampa nella pagina: se per quel selettore c'è una regola, il confronto si
> salta dicendolo.

## Caratteri dal catalogo

**Amministrazione → Aspetto → Caratteri.** L'elenco completo di Google Fonts —
**1941 famiglie** — con due scelte separate: una per l'applicazione (corsi, report, pannello:
le pagine dove si legge per ore) e una per le pagine pubbliche (che si vedono per pochi
secondi, e dove un carattere caratterizzato ha senso). Campo vuoto = carattere di partenza.

**Nessun visitatore contatta mai Google.** L'elenco viaggia con il progetto
(`database/google-fonts.json`, 50 KB) e serve solo a riempire il campo. Quando l'admin
sceglie una famiglia, **il server** scarica quel singolo file una volta sola in
`storage/fonts/` e da lì in poi lo serve Pistacchio, da `/assets/fonts/catalogo/{file}`. È la
stessa ragione per cui Albert Sans sta nel repository: un `<link>` a `fonts.googleapis.com`
farebbe arrivare a Google l'indirizzo IP di chi apre la pagina di accesso.

Si passa dal foglio di stile `css2` di Google e non dal file grezzo su GitHub perché il primo
restituisce un **woff2** già compresso e ridotto al latino — una trentina di KB — mentre il
`.ttf` è lo stesso carattere a 130 KB, e convertirlo in PHP non si può. Lo `User-Agent` nella
richiesta non è un vezzo: senza, Google risponde con indirizzi `.ttf`, credendo di parlare con
un browser vecchio.

### Se il server non può uscire su internet

Su molti hosting condivisi le connessioni in uscita sono chiuse. In quel caso **lo
scaricamento fallisce e l'impostazione non viene salvata** — di proposito: salvare il nome di
un carattere il cui file non esiste vorrebbe dire pagine che chiedono un file inesistente a
ogni caricamento.

Il messaggio dice sempre qual è il server e **che cosa fare**, e distingue tre casi
(`FontLibrary::messaggioErrore()`):

- **nessuna connessione**: l'hosting chiude le uscite, si carica il file a mano;
- **una risposta di rifiuto** — in pratica 401, 403 o 407, cioè un firewall o un proxy
  dell'hosting che risponde al posto di Google: si chiede al fornitore di aprire le uscite,
  oppure si carica il file a mano;
- **Google che non risponde adesso** (429 o un errore 5xx): di solito passa da solo, si
  riprova fra qualche minuto oppure si carica il file a mano.

Fino al 06/10 il secondo caso diceva solo «ha risposto 403 invece di 200», senza una strada.

**Il test non dipende dalla rete.** `caratteri_test.php` prova i tre messaggi senza
collegarsi a niente. Poi tenta uno scaricamento vero, ma solo per informazione: dove Google
risponde verifica che il file arrivato sia un woff2, dove non risponde stampa il messaggio che
il pannello mostrerebbe. Prima verificava il messaggio ottenuto dalla rete, e quindi il suo
esito dipendeva da dove lo si eseguiva.

Il ripiego funziona ovunque: si mette il file woff2 in `storage/fonts/` col nome della
famiglia in minuscolo e trattini — `playfair-display.woff2`. `FontLibrary::accettaCaricato()`
riconosce un woff2 dalla **firma del file** (`wOF2` nei primi quattro byte), non
dall'estensione, che la decide chi carica.

### Due dettagli di sicurezza

- **Il nome del file non arriva mai dall'indirizzo.** La rotta prende la famiglia dal
  catalogo e ricalcola il nome: un indirizzo come
  `/assets/fonts/catalogo/..%2f..%2fconfig.php` cerca una famiglia che non esiste e finisce in
  404, invece di diventare un percorso.
- **Dal CSS di Google si accetta solo un `fonts.gstatic.com/….woff2`.** Una risposta
  intercettata che indicasse un altro host farebbe scaricare qualunque cosa.

La rotta del carattere è **l'unica rotta di file che non chiede l'accesso**, e deve esserlo:
il carattere serve anche alla pagina di accesso, cioè a chi l'accesso non l'ha ancora fatto.
Un file di carattere non contiene dati di nessuno.

## Carattere delle pagine pubbliche (Albert Sans)

Accesso, registrazione, recupero e nuova password e cambio password obbligato usano
**Albert Sans**, in entrambi gli aspetti (guscio e affiancato). Dentro l'applicazione il
carattere resta quello di sistema: la scelta riguarda le pagine che si vedono **prima** di
entrare.

**Il file sta nel progetto e non si carica da Google.** Oltre alla coerenza con TinyMCE,
copiato qui per la stessa ragione, c'è un motivo preciso: un `<link>` a
`fonts.googleapis.com` farebbe contattare un server di Google al browser di chi apre la
pagina di accesso, mandandogli il proprio indirizzo IP prima ancora che abbia fatto accesso.
Nel 2022 il Landgericht di Monaco ha stabilito che farlo senza consenso viola il GDPR. Con il
file in casa non parte **nessuna richiesta a terzi**, e lo si può verificare: aprendo la
pagina di accesso, l'unico host contattato è il proprio.

`public/assets/fonts/albert-sans-latin.woff2` — **31 KB, un file solo**. È il font
**variabile**: contiene l'intero asse dei pesi da 100 a 900, quindi il grassetto non costa un
secondo scaricamento. È ridotto all'alfabeto latino più la punteggiatura usata dalle pagine;
l'originale completo pesa 129 KB. Il corsivo non è incluso, perché in quelle pagine non ce
n'è. Licenza **SIL Open Font License 1.1**: il file `albert-sans-OFL.txt` va tenuto accanto al
font, è la condizione che la licenza pone. Dettagli e procedura in
`public/assets/fonts/LEGGIMI.md`.

Due dettagli che è facile sbagliare, entrambi commentati nel codice:

- **I controlli dei form non ereditano il carattere**: il browser impone a `button`, `input`,
  `select` e `textarea` il proprio, quindi l'etichetta dentro il pulsante "Accedi" resterebbe
  nel carattere di sistema accanto a un modulo tutto in Albert Sans. Serve un
  `font-family: inherit` esplicito su quei quattro.
- **`font-display: swap`**: il testo compare subito con il carattere di sistema e viene
  sostituito appena il font è pronto. Il comportamento predefinito del browser è invece
  lasciarlo **invisibile** fino a tre secondi — su una pagina di accesso, un modulo senza
  etichette.

Il `<link rel="preload">` nella `<head>` chiede il font in parallelo al foglio di stile invece
che dopo. Porta `crossorigin` anche se il file è nostro: i font si scaricano sempre in
modalità CORS, e senza quell'attributo il browser lo scaricherebbe due volte.

## Icona nella scheda del browser

`public/favicon.ico` contiene l'icona di Pistacchio in quattro misure (16, 32, 48 e 64 px), più
`assets/img/pistacchio-32.png` per i browser moderni e `pistacchio-180.png` per la schermata
iniziale su telefono. I tag stanno in tutte le pagine che hanno una `<head>` propria: il layout
principale, le pagine di accesso, la verifica pubblica del certificato e l'installer.

Le immagini sono ricavate da `pistacchio_icon.png` **ritagliando sul contenuto**: l'originale ha
margini trasparenti che, ridotti a 16 px, lasciavano la forma minuscola in mezzo al nulla.

## Logo del gruppo

Nel modulo di creazione e in quello di modifica di un gruppo si può caricare un logo o simbolo.
Compare accanto al nome nell'elenco dei gruppi; senza immagine il gruppo mostra le proprie
iniziali su una tinta derivata dall'identificativo.

Il file viene ritagliato al centro in quadrato e ridotto a 256 px, e — a differenza delle
copertine dei corsi, che diventano JPEG — **salvato in PNG**, per conservare la trasparenza: un
logo appiattito su fondo bianco si vedrebbe come una toppa sopra lo sfondo caldo delle pagine.
Compare anche in **I miei gruppi**, nel profilo dello studente. I file stanno in
`storage/group-logos/`, fuori dal document root, serviti da `/gruppi/{id}/immagine` a chi ha
fatto accesso: l'indirizzo non sta sotto `/admin` proprio perché lo carica anche lo studente.

Creando un gruppo l'immagine viene salvata **dopo** la riga, perché il percorso contiene
l'identificativo, che prima non esiste. Sostituendo o rimuovendo l'immagine il file precedente
viene eliminato: qui la cancellazione automatica ha senso, perché un logo sostituito non serve
più a nessuno, a differenza di un video di lezione.

Migrazione `2026_09_17_logo_gruppo.sql` (colonna `logo_path`).

## Righe collegate che mancano: 404, non una pagina rotta

Quando una pagina si apre partendo da una riga — una lezione, un materiale, un questionario, un
tentativo — e la riga a cui è collegata non esiste più, la risposta è un **404** con la stessa
frase che il controller usa già per il proprio 404 ("Lezione non trovata.", "Questionario non
trovato."...). Prima la pagina si apriva lo stesso, con gli avvisi di PHP stampati sopra e un 403
fuorviante, o addirittura a 200 e sgangherata. Succede con dati importati male o cancellati a
mano, perché le chiavi esterne del database lo impedirebbero.

PHPStan è al **livello 8**, che è quello che segnala proprio le righe lette senza controllare
che esistano. Tre famiglie di rilievi restano escluse in `phpstan.neon`, ciascuna con il motivo
scritto accanto: i tipi del contenuto degli array, gli argomenti di tipo largo, e le chiamate a
`query()` che "potrebbero" restituire `false` — non può succedere, perché la connessione è
aperta con `PDO::ERRMODE_EXCEPTION`.

## Chi modifica cosa: tutor e assistenti

Regola decisa il 28/09 (`pistacchio-lms.md` §8.0), tutta in `App\Auth\CourseRights`:

- l'**admin** modifica tutti i corsi, ed è il solo a crearli, a riordinarli e ad assegnare
  tutor e assistenti;
- il **tutor** modifica solo i corsi assegnati ai **suoi** gruppi. Gli altri li vede in sola
  lettura: nessun comando di modifica, e in Gestione corsi l'etichetta "sola lettura";
- la stessa regola vale per **report** e **sessioni live**: il tutor vede i suoi corsi, i suoi
  gruppi e i loro studenti;
- l'**assistente** può affiancare **più tutor** (tabella `assistant_tutors`) e vede i report
  degli studenti dei gruppi di tutti.

Prima ogni azione su moduli, lezioni e questionari controllava solo il ruolo, in una quarantina di
punti: ora passano tutti da `CourseRights::requireEdit*()`, che ferma con un 403 anche chi
scrive a mano l'indirizzo di un corso altrui.

Il permesso `assistant.manage` al tutor è **vietato per regola**, non per scelta: la matrice dei
permessi lo mostra bloccato, e il salvataggio lo rifiuta anche se arriva da una richiesta
costruita a mano (`RolePermissionModel::FORBIDDEN`).

Due migrazioni, in quest'ordine. `2026_09_29_tutor_assistenti.sql` crea `assistant_tutors`,
vi trasferisce i legami esistenti di `users.supervising_tutor_id` e toglie il permesso al
tutor; `2026_09_30_rimozione_supervising_tutor_id.sql` **elimina la colonna**, che nel
frattempo non la leggeva più nessuno. Entrambe si possono rieseguire senza danni.

**La colonna Tutor dell'elenco utenti** — e la colonna «Tutor di riferimento» dello
scaricamento CSV/XLSX — mostra il tutor di riferimento **secondo il ruolo**: per uno studente
i tutor dei gruppi di cui fa parte, per un assistente i tutor che affianca, per tutor e admin
nessuno («—»). Più tutor si elencano in ordine alfabetico, ciascuno una volta sola.
L'espressione sta in `UserModel::TUTOR_DI_RIFERIMENTO` e la usano tutti e tre gli elenchi
(admin, tutor, scaricamento). Fino al 06/10, dopo la rimozione del tutor supervisore, la
colonna guardava solo gli assistenti e restava vuota sulle righe degli studenti.

## Ordine dei corsi

Nella pagina **Corsi**, chi può modificarli riordina le schede **trascinandole**, e l'ordine si
salva da solo, in silenzio, senza ricaricare la pagina.

Durante il trascinamento la scheda **segue il puntatore**: lo spostamento si ricava ogni volta
da dove la scheda si trova adesso, meno lo spostamento che le è già stato dato, invece di
tenere il conto di un'origine — tenendolo, dopo ogni scambio il conto si perdeva e la scheda
rimbalzava avanti e indietro. Sta sopra le altre ed è trasparente al puntatore — altrimenti, seguendo il cursore, sarebbe lei stessa
l'elemento puntato e non si saprebbe mai su quale scheda si sta passando. Finché una scheda sta scivolando non si scambia niente: a metà volo la sua posizione misurata è
quella dell'animazione e non quella vera, e il confronto con il confine darebbe scambi
incoerenti, annullati subito dopo — è il residuo di irregolarità che restava dopo la prima
correzione. Le altre **scorrono** verso la nuova posizione in 160 ms invece di saltarci: si misura dove sono prima, si cambia
l'ordine, si misura dove sono finite, e ognuna viene riportata otticamente indietro e lasciata
scivolare. Chi ha chiesto meno animazioni nelle impostazioni del sistema non ne vede nessuna.

Il trascinamento non usa quello nativo dell'HTML: le schede sono collegamenti, e il browser
avvia il proprio trascinamento del link invece del nostro. `course-order.js` segue gli eventi
del puntatore, con una soglia di 6 px perché un clic non perfettamente fermo non diventi un
trascinamento, e la cattura del puntatore si prende **solo quando il trascinamento comincia
davvero**: presa prima, il clic finisce alla griglia e un clic semplice non apre più il corso.
Vale solo con il mouse: su un touch screen bloccare lo scorrimento della pagina per permettere
il trascinamento renderebbe la pagina difficile da leggere.

L'ordine vale ovunque: elenco dei corsi, Gestione corsi e **catalogo degli studenti**.

Migrazione `2026_09_18_ordine_corsi.sql` (colonna `position`, inizializzata con l'ordine che le
righe avevano finora, dal più recente: altrimenti sarebbero tutte a zero e il primo
caricamento sembrerebbe casuale).

`CourseModel::reorder()` ignora gli id sconosciuti e lascia in coda i corsi non nominati: la
pagina di chi trascina potrebbe essere vecchia di qualche minuto, e un corso creato nel
frattempo non deve sparire né bloccare il salvataggio.

## Ordine di moduli e lezioni

Nella pagina del corso, chi può modificarlo trova accanto a ogni modulo e a ogni lezione due
frecce che le spostano di un posto su o giù. Le frecce agli estremi sono disabilitate, e dopo
lo spostamento la pagina torna al modulo su cui si stava lavorando, invece che in cima: in un
corso lungo, altrimenti, dopo ogni clic si perde il segno.

Funziona senza JavaScript: sono moduli POST, come il riordino dei materiali della lezione, di
cui `ModuleModel::move()` e `LessonModel::move()` ricalcano il meccanismo. Il vicino con cui si
scambia la posizione è la riga **adiacente nell'ordine di visualizzazione**, non quella con
`position ± 1`: dopo un'eliminazione le posizioni hanno dei buchi, e righe importate potrebbero
condividere lo zero — in quel caso si rinumera prima di scambiare.

## Copertina di avvio e completamento delle lezioni di solo video

Sono due cose distinte, tenute separate di proposito: la copertina è **presentazione** e vale
per ogni video; lo sblocco del pulsante è una **regola didattica** e riguarda solo certe
lezioni. Legarle significherebbe che cambiare idea sull'una tocca l'altra.

### La copertina, per ogni video

Davanti a ogni video, qualunque sia il provider, c'è una copertina con il pulsante "Guarda la
lezione". Prima era solo sui video Bunny e Cloudflare, per una ragione tecnica, e due lezioni
affiancate avevano un aspetto diverso senza che lo studente potesse capire perché.

Finché la copertina è lì il player non viene caricato: l'iframe non ha indirizzo e il video
sul nostro server ha `preload="none"`. La pagina quindi non contatta Bunny o Cloudflare, e non
scarica nulla dal nostro server, finché nessuno guarda.

La copertina è nascosta nell'HTML e la scopre `public/assets/js/lesson-video.js`: senza
JavaScript resta invisibile e il player si comporta come ha sempre fatto. Per i soli provider
esterni c'è in più un `<noscript>` con il player già caricato, altrimenti lì l'iframe
resterebbe senza indirizzo.

Il pulsante "Senza distrazioni" compare **solo dopo l'avvio del video**: finché c'è la
copertina il player è nascosto, e allargarlo darebbe una pagina nera attorno a un riquadro
vuoto. `lesson-video.js` annuncia l'avvio con un evento e `lesson-focus.js` lo ascolta, per cui
il primo va caricato prima del secondo.

### Lo sblocco, per le lezioni di solo video

In una lezione che contiene **solo** un video — niente testo, niente materiali — il pulsante
"Segna come completata" resta disabilitato finché lo studente non avvia il video. Il clic sulla
copertina è il segnale, e per i video sul nostro server vale anche l'evento `play` del player.

Il pulsante è **abilitato nell'HTML** e lo disabilita `public/assets/js/lesson-video.js`, lo
stesso script della copertina: senza JavaScript, o se qualcosa va storto, lo studente può
comunque concludere la lezione. Sempre per questo, un `<noscript>` contiene lo stesso player
caricato subito, altrimenti senza JavaScript l'iframe resterebbe senza indirizzo e il video
non si vedrebbe. Il riquadro dichiara con `data-attende-avvio` se la regola lo riguarda.

Il ricordo dell'avvio sta in `sessionStorage`, quindi ricaricando la pagina il pulsante resta
sbloccato. Non segue lo studente su un altro dispositivo, e non è un controllo: chi vuole può
premere play e segnare subito. Serve a evitare la distrazione, non la furbizia.

La copertina del riquadro di avvio è disegnata da `App\Core\VideoPoster`: un SVG in toni
pastello con tinte e composizione derivate dall'identificativo della lezione, così ogni video ha
la sua senza che nessuno debba preparare un'immagine.

## Modalità senza distrazioni

Nella lezione, sotto il video, un pulsante **Senza distrazioni** allarga il player a tutto lo
schermo scurendo il resto della pagina. La sceglie lo studente: non si attiva mai da sola.

Il pulsante **Torna alla lezione** resta fisso in alto a destra per tutto il tempo — niente
comparsa al passaggio del mouse, che su uno schermo tattile non esiste — e anche Esc riporta
indietro.

Il riquadro del video non viene mai spostato nell'albero del documento: cambiano solo le
classi (`public/assets/js/lesson-focus.js`). Un iframe spostato si ricarica e il video
ripartirebbe da capo; così invece la riproduzione prosegue senza interruzione, sia con i
player Bunny e Cloudflare sia con i video self-hosted.

I due pulsanti stanno nell'HTML con l'attributo `hidden` e li scopre il JavaScript: senza
JavaScript la pagina resta esattamente quella di prima.

## Configurazione dal pannello

**Amministrazione → Posta elettronica** e **Amministrazione → Google Meet** permettono di
cambiare la configurazione senza aprire il `.env`. Le due pagine richiedono il permesso
`settings.manage`, assegnato all'admin e spostabile dalla matrice dei permessi.

I valori finiscono nella tabella `settings`, con chiavi che hanno gli stessi nomi delle
variabili d'ambiente, e **hanno la precedenza sul `.env`**. Svuotare un campo cancella la riga
e restituisce il comando al file. Il `.env` non viene mai riscritto da una pagina web: contiene
anche le credenziali del database.

La password SMTP non torna mai al browser: la pagina dice solo se è impostata, e salvando con
il campo vuoto resta quella di prima. La chiave dell'account di servizio Google non va in
tabella ma in `storage/google/`, fuori dal document root e con permessi 0600; in tabella resta
il percorso. Sostituendo o rimuovendo la chiave, il file precedente viene eliminato — ma solo
se l'avevamo caricato noi, non se il percorso arriva dal `.env`.

Ogni pagina ha una prova: l'invio di un messaggio al proprio indirizzo, e una lettura del
calendario configurato che verifica in un colpo solo credenziali, delega a livello di dominio
e visibilità del calendario, senza creare né modificare eventi.

## Copertina del corso

Ogni corso puo' avere una copertina, caricata da **Gestione corsi → il corso → Copertina**.
Compare nell'elenco dei corsi, nel catalogo e in cima alla pagina del corso.

L'immagine viene **ritagliata al centro in 16:9** e salvata in due misure (1280 px per la
testata, 640 px per le card), convertita in JPEG. I file stanno in `storage/course-covers/`,
fuori dal document root, e sono serviti da `/corsi/{id}/copertina` (misura grande) e
`/corsi/{id}/copertina/piccola`: serve aver fatto accesso, ma **non** essere iscritti, perche'
la copertina compare anche nel catalogo. Sostituendo o rimuovendo l'immagine i file precedenti
vengono eliminati.

Il **testo alternativo** e' un campo a parte: lo leggono i lettori di schermo e compare se
l'immagine non si carica. Se resta vuoto, la vista usa il titolo del corso. Si puo' correggere
senza ricaricare l'immagine.

Un corso senza copertina non mostra un riquadro vuoto ma le sue iniziali, su una tinta
derivata dall'identificativo.

La colonna `cover_image` esisteva gia' nello schema, pensata per un URL esterno: un valore che
comincia per `http://` o `https://` continua a essere usato come indirizzo, senza passare da
`/storage`.

## Contenuto delle lezioni

Il campo **Contenuto della lezione** usa **TinyMCE**, incluso nel progetto sotto
`public/assets/vendor/tinymce` (nessuna chiamata al cloud di TinyMCE, nessuna chiave API;
licenza GPL, vedi il `README-pistacchio.md` in quella cartella).

- **Immagini**: si caricano dalla finestra *Inserisci immagine → Carica*, oppure trascinandole
  o incollandole nell'editor. Il file finisce in `storage/lesson-images/{lezione}/`, quindi
  **fuori dal document root**: viene servito da `/lessons/{id}/images/{file}` solo a chi è
  iscritto al corso, come già avviene per i video. Formati: jpg, png, gif, webp — max 8 MB.
  Le immagini si caricano solo dopo aver creato la lezione (prima non esiste una cartella).
- **Video incorporati**: *Inserisci → Media* con un link YouTube o Vimeo. Gli iframe di altri
  host vengono scartati al salvataggio.
- **PDF e altri documenti** non vanno dentro al testo: si caricano tra i **materiali
  scaricabili**, in fondo alla pagina di modifica. Ogni materiale mostra icona del formato,
  tipo e dimensione, e si può spostare su e giù: l'ordine dell'elenco è quello che vedono
  gli studenti.

L'HTML dell'editor viene stampato nella pagina della lezione **senza escape** — è l'unico modo
di rendere la formattazione — quindi passa da `HtmlSanitizer` **in scrittura**: sopravvive solo
ciò che è in lista consentita (testo formattato, titoli, elenchi, tabelle, link, immagini e
iframe YouTube/Vimeo). Tutto il resto, gestori di eventi compresi, viene rimosso.

## Profilo dell'utente

Da **Profilo** (`/profilo`), voce sempre presente nella barra laterale, chiunque abbia fatto
accesso compila i propri dati — nome, città, telefono, una breve presentazione — e carica
un'immagine. L'email non si cambia da qui: è la credenziale di accesso e cambiarla richiederebbe
una nuova verifica, quindi resta al pannello utenti.

L'immagine viene **ritagliata quadrata al centro e ridotta a 512 pixel** (GD, qualità 85): chi
carica la foto della fotocamera non deve prepararla, e il server non si ritrova a spedire 4 MB
a ogni pagina. Senza l'estensione GD il file viene salvato così com'è. Come le altre immagini
del progetto sta in `storage/avatars/`, fuori dal document root, e passa da
`/utenti/{id}/immagine`. Il nome del file cambia a ogni caricamento,
quindi la cache del browser non mostra mai quella vecchia, e il file precedente viene eliminato.

La miniatura tonda compare accanto al nome in fondo alla barra laterale, in ogni pagina, e
porta al profilo; chi non ha ancora caricato nulla vede l'iniziale del proprio nome.

**Chi vede la foto** lo decide `App\Auth\GroupPeers`: la persona stessa, l'amministratore, i
compagni di gruppo e il tutor del gruppo. L'assistente no. Per tutti gli altri l'indirizzo
risponde 404 come per una foto che non c'è, così non si può scoprire chi l'ha caricata. Fino al
06/10 bastava aver fatto accesso, e con un numero a caso nell'indirizzo si vedeva la foto di
chiunque. Sotto il caricamento, nel profilo, un avviso dice che foto e presentazione sono
facoltative e dove compariranno: la frase e la regola vanno cambiate insieme.

### Nome e cognome, e come compaiono gli studenti agli altri

Dal 07/10 **nome e cognome sono due campi** (`first_name`, `last_name`), obbligatori tutti e
due in registrazione, nel profilo, nel pannello e nell'importazione. Nella registrazione stanno
affiancati, e vanno uno sotto l'altro da soli quando lo spazio non basta (`.campi-affiancati`). **`full_name` resta**, ma è una colonna
**calcolata dal database** (nome + cognome): le pagine e i report che lo leggono non sono
cambiati, e nessuno lo scrive più. Una scrittura dimenticata su `full_name` dà errore subito.

**Lo studente sceglie come lo vedono gli altri studenti**: nome e cognome, solo il nome, o solo
le iniziali. In quest'ordine, da «Nome e cognome» in alto a «Solo le iniziali» in basso, in ogni
pagina che le mostra: l'ordine è quello di `PersonName::SCELTE`, e le pagine lo leggono da lì
(09/10). **Finché non sceglie, gli altri lo vedono con le sole iniziali** (protezione
predefinita, dal 08/10): nessuno è esposto con nome e cognome senza averlo deciso, nemmeno chi
non ha ancora fatto accesso. La prima scelta si fa nella pagina «Primo accesso» (qui sotto),
le successive nel profilo («M. R.»: una per parola, il trattino separa,
l'apostrofo no). La scelta ha un riquadro suo, «Come ti vedono gli altri studenti», separato
dai dati anagrafici e con il suo salvataggio (`POST /profilo/come-ti-vedono`, solo per gli
studenti); il titolo è quello di ogni riquadro, sopra le scelte per misura e per peso. Ogni
scelta mostra l'anteprima del proprio nome. **Tutor e admin vedono
sempre nome e cognome**, ognuno vede se stesso per intero, e il certificato e i report li
riportano per intero. Oggi la pagina del gruppo è l'unico posto in cui uno studente vede gli
altri studenti: lì il nome mostrato prende il posto di quello vero anche per le iniziali della
foto e per la scheda della presentazione, e l'ordine segue il nome mostrato. **La foto è una
scelta a parte**: chi non la carica compare con le iniziali di quello che ha scelto di mostrare.

La regola sta in `App\Core\PersonName` (`shown()`), provata da `tests/nome_test.php`. La
migrazione divide i nomi che c'erano già con la prima parola come nome, e alla fine **stampa
chi ha più di due parole**, da controllare a mano.

**Ognuno vede se stesso per intero**, in tutta la piattaforma (anche accanto a «Esci»). Per
poter verificare la propria scelta, **nella pagina del gruppo, sotto il proprio nome e visibile
solo a lui, lo studente legge «Gli altri ti vedono come «Franco»»** (08/10, Elena), in piccolo.
Nel cerchio sta su una riga sola, e sporge verso l'esterno, mai sulla foto; nella griglia del
telefono e del tablet, dove le colonne sono strette, va a capo. I nomi del gruppo sono a 16 px.
È testo e non un collegamento: il nome può stare dentro il pulsante della presentazione, e un
collegamento dentro un pulsante non si raggiunge. Lo staff non la vede: compare sempre intero.

**Una pagina nuova che mostri a uno studente il nome di un altro studente deve passare da
`PersonName::shown()`.**

### La presentazione

Il campo **Presentazione** del profilo è facoltativo e lungo al massimo **1.000 caratteri**
(`ProfileController::MAX_BIO_CHARS`; erano 2.000 fino al 06/10). Il limite è doppio, come per
le risposte aperte dei questionari: `maxlength` nel campo e il taglio sul server. Sopra il
campo, sulla stessa riga dell'etichetta e allineato a destra, c'è lo stesso contatore che
scala delle risposte aperte, con lo stesso script
(`quiz-open-count.js`): il numero di partenza lo scrive il server contando il testo già
salvato, così senza JavaScript il contatore resta fermo ma dice il vero. La presentazione la
vedono le stesse persone che vedono la foto: i compagni di gruppo, il tutor e l'admin.

### La pagina del gruppo

In **I miei gruppi**, nel profilo, il nome di ogni gruppo porta a `/gruppi/{id}`: chi ne fa
parte, con la foto o le iniziali, **senza email**. Il titolo è «Gruppo» seguito dal nome
(«Gruppo Verde»), perché un nome di una parola da solo non dice di che pagina si tratta; se il
nome comincia già con «Gruppo», la parola non si ripete. Non c'è una voce nella barra laterale, per
scelta: è una pagina che si apre ogni tanto, non un luogo dove si torna.

- **Su computer** i partecipanti stanno **in cerchio**, con il tutor al centro e i nomi verso
  l'esterno: di fianco sui due lati, sopra in cima e sotto in fondo.
- **Tutte le foto hanno un bordo sottile**, tutor compreso: 1 px del colore principale
  della tavolozza. È un'ombra (`box-shadow`) e non un bordo, così non cambia la
  misura della foto né sposta il cerchio.
- **Sul telefono**, e su computer **oltre 20 partecipanti**, una griglia di foto con il nome
  sotto, con il tutor da solo nella prima riga.

Il cerchio si accende con una query di contenitore (52 rem di spazio), come le schede delle
tabelle: dove non è supportata resta la griglia. La geometria sta in `App\Core\GroupCircle`,
senza database, ed è provata da `tests/cerchio_test.php`.

La pagina la aprono i partecipanti, il tutor del gruppo e l'amministratore — la stessa regola
delle foto, nella stessa classe.

**La presentazione si apre sopra la pagina.** Chi ne ha scritta una è un pulsante — foto e
nome insieme — con un piccolo fumetto verde sull'angolo della foto (è la forma a dirlo; il
colore principale la accompagna e cambia con la tavolozza).
Toccandolo si apre una scheda con foto, nome e il testo **in corsivo** fra due **virgolette
giganti** — un racconto detto a voce più che un testo da consultare —, che si chiude con la ✕,
con Esc o toccando fuori; il cerchio non si sposta.

Le virgolette sono **forme, non caratteri**: due maschere CSS ricavate dalle virgolette di
Noto Serif (SIL Open Font License 1.1), del colore principale. La prima versione usava “ e ”
in Georgia, e la loro posizione dipendeva dal carattere installato: ogni carattere disegna la
virgoletta in un punto diverso della propria riga, e su Windows la chiusura finiva a metà del
testo. Una forma ha la scatola uguale al segno: quella che apre sta accanto alla prima riga,
quella che chiude accanto all'ultima, per quante righe abbia il testo, e non fanno scorrere la
scheda. `accessibilita.js` controlla tutte e due le posizioni, che una scheda che non ha
raggiunto la sua altezza massima non scorra in verticale e che il fumetto sia del colore
principale. Su computer la scheda è centrata, sul telefono sale
dal fondo e occupa tutta la larghezza; un testo lungo scorre dentro la scheda.

- **Niente JavaScript**: la scheda è un `popover` aperto dall'attributo `popovertarget`, e il
  browser gestisce da sé Esc, il clic fuori e il ritorno del fuoco.
- **Il ripiego è naturale**: le schede stanno nell'HTML dopo il cerchio, non dentro. Un browser
  che non conosce i popover le mostra come un elenco di presentazioni sotto i partecipanti.
- **Nessun `display` sulla scheda chiusa**: batterebbe la regola con cui il browser la tiene
  nascosta (la trappola di `hidden` e dei `details`), e le schede chiuse coprirebbero la
  pagina. Il `display` si dà solo a `:popover-open`. `accessibilita.js` apre ogni scheda della
  pagina seminata e controlla che da chiusa non occupi spazio, da aperta stia dentro lo
  schermo senza scorrere in orizzontale, che la ✕ sia un bersaglio da 24 px e che Esc la chiuda
  riportando il fuoco sulla persona.

## Registrazione e iscrizione degli studenti

### Registrazione
Chiunque può creare un account dalla pagina `/register`: il ruolo assegnato è sempre
`studente`, gli altri restano appannaggio del pannello. L'account nasce **non verificato** e il
login viene rifiutato finché l'indirizzo non è confermato con il link ricevuto per email, valido
24 ore e utilizzabile una sola volta.

Se l'indirizzo è già registrato, la pagina di esito è identica a quella di una registrazione
riuscita e non viene inviata alcuna email: la registrazione non deve diventare un modo per
scoprire chi è iscritto alla piattaforma. Stesso criterio per il recupero password, che risponde
sempre allo stesso modo. Entrambi i flussi accettano al massimo 5 richieste all'ora per account.

Gli account creati dallo staff e quello dell'amministratore creato dall'installer nascono già
verificati: l'indirizzo lo ha scelto chi li ha creati.

### Modalità di iscrizione di un corso
Ogni corso ha un campo `enrollment_mode`, impostabile dalla sua scheda in *Gestione corsi*:

| Modalità | Cosa comporta |
|---|---|
| `closed` (predefinita) | Iscrive solo lo staff, oppure l'assegnazione del corso a un gruppo. Il corso non compare nel catalogo |
| `request` | Lo studente chiede di iscriversi, admin e tutor ricevono un'email e decidono dalla scheda del corso |
| `open` | Lo studente si iscrive da solo dal catalogo, senza attese |

Il catalogo (`/catalogo`, voce *Esplora corsi*) elenca solo corsi **pubblicati** in modalità
`open` o `request`, escludendo quelli a cui lo studente è già iscritto. Una richiesta in attesa
**non** dà accesso al corso: le richieste vivono in una tabella separata e l'iscrizione vera
nasce solo con l'approvazione.

### Email inviate
Conferma dell'indirizzo, recupero password, conferma di iscrizione allo studente, avviso ad
admin e tutor per le richieste da valutare, esito negativo di una richiesta. Tutte in testo
semplice.

## Configurazione dell'invio email

`MAIL_TRANSPORT` sceglie come vengono recapitate:

- **`log`** (predefinito) — i messaggi vengono salvati come file `.eml` in `storage/mail` e non
  spediti. È il modo di lavorare in locale: il link di verifica si legge aprendo il file.
- **`smtp`** — server SMTP esterno, con `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`,
  `MAIL_PASSWORD` e `MAIL_ENCRYPTION` (`tls` per la porta 587, `ssl` per la 465, `none`).
- **`mail`** — la funzione `mail()` di PHP, che richiede un MTA configurato sul server.

Il client SMTP è scritto in casa (`app/Core/Mail`), senza dipendenze: apre la connessione, fa
EHLO, eventualmente STARTTLS, si autentica con AUTH LOGIN e invia il messaggio.

Un invio che fallisce non blocca mai l'operazione che lo ha generato, con un'eccezione: se non
parte l'email di verifica in fase di registrazione, l'utente resterebbe con un account
inaccessibile, quindi il problema gli viene detto (e con `APP_DEBUG=1` viene mostrato anche il
link, utile in sviluppo).
