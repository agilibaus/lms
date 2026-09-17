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
- **Quiz** a scelta multipla/vero-falso con verifica automatica del punteggio
- **Certificati** di completamento generati in PDF, con codice di verifica pubblico
- **Report/dashboard** su progressi utente/corso, risultati quiz, presenze alle sessioni live
- **Ruoli utente**, con privilegi configurabili nella tabella `role_permissions` (nessun privilegio hardcodato nel codice):
  
  | Ruolo | Ambito |
  |---|---|
  | `admin` | Gestione completa: corsi, utenti, gruppi, permessi, certificati |
  | `tutor` | Modifica corsi assegnati, corregge quiz, segue gruppi/coorti, gestisce i propri assistenti |
  | `assistente` | Affianca un tutor specifico (`supervising_tutor_id`), non l'admin: correzioni e report solo sugli ambiti assegnati |
  | `studente` | Si registra da solo, si iscrive ai corsi aperti, svolge quiz, scarica i propri certificati |
- **Profilo personale**: ogni utente compila i propri dati e carica un'immagine, che compare tonda accanto al suo nome
- **Gruppi**: classi/coorti di studenti, con corsi assegnabili all'intero gruppo oltre che al singolo utente
- **Sessioni live** integrate con **Google Meet**, tramite Google Calendar API (`conferenceData`) — richiede un account di servizio Google con accesso al Calendar

## Stato del progetto

In sviluppo iniziale.
- ✅ Schema database (`database/schema.sql`)
- ✅ Scaffold applicativo: router, autenticazione/sessioni, connessione PDO, layout responsive, lista/dettaglio corsi
- ✅ Moduli/lezioni con upload materiali ed embed video (Bunny/Cloudflare Stream o self-hosted)
- ✅ Quiz (scelta singola / vero-falso), tentativi illimitati, sblocco progressivo dei moduli
- ✅ Certificati PDF con emissione automatica, revoca e verifica pubblica per codice
- ✅ Report per corso, studente e gruppo, con export CSV
- ✅ Pannello di amministrazione: utenti, gruppi, corsi/iscrizioni e matrice dei permessi
- ✅ Protezione CSRF su tutte le richieste POST
- ✅ Sessioni live su Google Meet, con presenze e fallback a link manuale
- ✅ Procedura di installazione guidata dal browser
- ✅ Registrazione autonoma con verifica email, recupero password, catalogo e auto-iscrizione
- ✅ Editor ricco nella lezione, immagini caricate e materiali ordinabili
- ✅ Profilo personale con immagine

## Requisiti

- PHP **8.1 o superiore**, con estensioni `pdo_mysql`, `mbstring`, `dom`, `gd` (le ultime due richieste da Dompdf per i certificati)
- MySQL 8+ o MariaDB 10.6+
- Server web con supporto al rewrite degli URL (Apache + `mod_rewrite`, oppure Nginx configurato in modo equivalente)
- Composer (autoload PSR-4 e installazione di Dompdf: senza `composer install` i certificati non possono essere generati)
- Estensioni `openssl` e `curl` per l'integrazione Google (già presenti in quasi tutte le installazioni)
- Per le sessioni live: un progetto Google Cloud con **Calendar API** abilitata e un account di servizio con delega a livello di dominio (vedi sotto). Senza, le sessioni restano utilizzabili con link Meet inseriti a mano

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
Le ultime migrazioni sono `2026_09_16_materiali_lezione.sql` (colonna `position` sui materiali),
`2026_09_16_profilo_utente.sql` (campi del profilo e immagine) e
`2026_09_17_copertina_corso.sql` (testo alternativo della copertina del corso) e
`2026_09_17_impostazioni.sql` (tabella `settings` e permesso `settings.manage`).

## Struttura del progetto

```
/public              → document root
  /assets/css         → stylesheet
  /assets/vendor/tinymce → editor di testo ricco (vedi README-pistacchio.md nella cartella)
  /install            → procedura di installazione guidata (da eliminare dopo l'uso)
  index.php           → front controller
  .htaccess           → rewrite verso index.php
/app
  /Controllers        → logica delle route (AuthController, CourseController, ModuleController, LessonController, QuizController, CertificateController, ReportController)
    /Admin             → pannello di amministrazione (UserController, GroupController, CourseController, PermissionController)
  /Models             → accesso dati via PDO/query preparate (UserModel, CourseModel, ModuleModel, LessonModel, Quiz*, CertificateModel, GroupModel, ReportModel...)
  /Auth               → login, sessione, permessi per ruolo (Auth.php)
  /Core               → Router minimale, Database (PDO), Env, View, Upload, VideoEmbed, CourseAccess, CertificateService, Csv, Csrf, HtmlSanitizer, FileType
    /Google            → client minimale per Calendar API (ServiceAccountClient, MeetCalendar, trasporto HTTP)
  /Views
    /partials          → layout condiviso (shell.php)
  routes.php
/config
  config.php           → bootstrap (env, error reporting, timezone)
/storage
  /videos
  /materials
  /lesson-images       → immagini inserite nel testo delle lezioni
  /avatars             → immagini del profilo
  /certificates
/database
  schema.sql
  /migrations         → migrazioni incrementali per installazioni gia' esistenti
/tests
  google_meet_test.php  → test del client Google (senza rete né credenziali reali)
  html_sanitizer_test.php → test del sanificatore HTML dell'editor
  course_cover_test.php   → test della copertina del corso (ritaglio, misure, testo alternativo)
  settings_test.php       → test delle impostazioni salvate in tabella (richiede il database)
```

## Quiz, certificati e report

### Quiz
Ogni modulo puo' avere **un quiz** (domande a scelta singola o vero/falso). Il tutor imposta
la soglia di superamento in percentuale; i **tentativi sono illimitati** e allo studente vale
sempre il punteggio migliore. Le risposte corrette non vengono mai inviate al browser durante
lo svolgimento, e la correzione avviene lato server verificando che l'opzione scelta appartenga
davvero alla domanda.

### Sblocco progressivo dei moduli
Se un modulo ha il flag **"quiz obbligatorio"**, tutti i moduli successivi restano bloccati
(lezioni comprese) finche' lo studente non supera quel quiz. Lo staff non e' mai soggetto al
blocco. Un modulo marcato come obbligatorio ma privo di quiz — o con un quiz senza domande —
non blocca nulla, per evitare vicoli ciechi.

### Certificati
Il certificato viene emesso **automaticamente** quando lo studente ha completato tutte le
lezioni del corso **e** superato tutti i quiz presenti. Il PDF (A4 orizzontale, generato con
Dompdf) viene salvato in `storage/certificates/` e non e' mai raggiungibile direttamente da
`public/`: il download passa da un endpoint autenticato. Ogni certificato ha un codice di
verifica pubblico consultabile su `/verify/{codice}`, pagina che non richiede login e mostra
solo intestatario, corso e data. Admin e tutor possono emettere un certificato manualmente
(anche in deroga ai requisiti) o revocarlo: un certificato revocato non e' piu' scaricabile,
risulta "revocato" nella verifica pubblica e non viene rigenerato dall'emissione automatica.

### Report
Disponibili in `/reports`, con export CSV di ogni vista:

| Report | Contenuto |
|---|---|
| Per corso | Iscritti con progresso, lezioni completate, quiz superati, stato certificato |
| Per studente | Tutti i corsi dello studente, con dettaglio tentativi e punteggi per quiz |
| Per gruppo | Membri del gruppo incrociati con i corsi assegnati al gruppo |

I permessi seguono `role_permissions`: `report.view` (admin, tutor) da' accesso completo,
`report.view_assigned` (assistente) limita la vista agli studenti dei gruppi seguiti dal
proprio tutor di riferimento (`supervising_tutor_id`).

## Pannello di amministrazione

Raggiungibile dalla sezione **Amministrazione** della sidebar, che mostra solo le voci
consentite dai permessi dell'utente.

### Utenti (`/admin/users`)
Creazione utenti con ruolo, stato attivo/disattivo e password iniziale (minimo 8 caratteri),
modifica e reimpostazione password. Serve `user.manage`; chi ha solo `assistant.manage`
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
progresso, tentativi quiz e certificati; per rimuoverle davvero si usa la scheda del corso.

### Corsi e iscrizioni (`/admin/courses`)
Creazione (`course.create`), modifica e iscrizioni (`course.edit`), eliminazione
(`course.delete`). Lo slug è generato dal titolo e reso univoco in automatico. I contenuti
(moduli, lezioni, quiz) restano nella scheda del corso. La rimozione di un'iscrizione cancella
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

## Test

```bash
php tests/google_meet_test.php
php tests/html_sanitizer_test.php
php tests/course_cover_test.php
php tests/settings_test.php
```

Verifica il client Google senza rete e senza credenziali reali: genera una chiave RSA al volo,
controlla che la JWT sia firmata correttamente (verifica con la chiave pubblica) e che la
richiesta a Calendar contenga i parametri giusti, simulando le risposte di Google — compresi
gli errori 403 e 404.

`html_sanitizer_test.php` verifica che l'HTML salvato dall'editor non possa contenere codice
eseguibile: script, gestori di eventi, `javascript:`, `data:` e iframe da host non previsti.

`course_cover_test.php` verifica il ritaglio 16:9 e le due misure della copertina, la scelta
del file da servire, l'eliminazione di entrambe le misure, la normalizzazione del testo
alternativo e le iniziali mostrate quando la copertina manca. Richiede l'estensione GD.

## Incontri dal vivo nella pagina della lezione

Sotto il video, la lezione mostra gli incontri **del proprio modulo** ancora da fare o in
corso; quelli passati non compaiono. Il pulsante "Entra nella riunione" si attiva da un quarto
d'ora prima dell'inizio fino alla fine, e prima di allora resta la sola data. Chi non ha ancora
il link Meet vede scritto che non è disponibile.

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
`/utenti/{id}/immagine`, che richiede l'accesso. Il nome del file cambia a ogni caricamento,
quindi la cache del browser non mostra mai quella vecchia, e il file precedente viene eliminato.

La miniatura tonda compare accanto al nome in fondo alla barra laterale, in ogni pagina, e
porta al profilo; chi non ha ancora caricato nulla vede l'iniziale del proprio nome.

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
