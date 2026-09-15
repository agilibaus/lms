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
- **Quiz** a scelta multipla/vero-falso con verifica automatica del punteggio
- **Certificati** di completamento generati in PDF, con codice di verifica pubblico
- **Report/dashboard** su progressi utente/corso, risultati quiz, presenze alle sessioni live
- **Ruoli utente**, con privilegi configurabili nella tabella `role_permissions` (nessun privilegio hardcodato nel codice):
  
  | Ruolo | Ambito |
  |---|---|
  | `admin` | Gestione completa: corsi, utenti, gruppi, permessi, certificati |
  | `tutor` | Modifica corsi assegnati, corregge quiz, segue gruppi/coorti, gestisce i propri assistenti |
  | `assistente` | Affianca un tutor specifico (`supervising_tutor_id`), non l'admin: correzioni e report solo sugli ambiti assegnati |
  | `studente` | Visualizza corsi iscritti, svolge quiz, scarica i propri certificati |
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

## Struttura del progetto

```
/public              → document root
  /assets/css         → stylesheet
  /install            → procedura di installazione guidata (da eliminare dopo l'uso)
  index.php           → front controller
  .htaccess           → rewrite verso index.php
/app
  /Controllers        → logica delle route (AuthController, CourseController, ModuleController, LessonController, QuizController, CertificateController, ReportController)
    /Admin             → pannello di amministrazione (UserController, GroupController, CourseController, PermissionController)
  /Models             → accesso dati via PDO/query preparate (UserModel, CourseModel, ModuleModel, LessonModel, Quiz*, CertificateModel, GroupModel, ReportModel...)
  /Auth               → login, sessione, permessi per ruolo (Auth.php)
  /Core               → Router minimale, Database (PDO), Env, View, Upload, VideoEmbed, CourseAccess, CertificateService, Csv, Csrf
    /Google            → client minimale per Calendar API (ServiceAccountClient, MeetCalendar, trasporto HTTP)
  /Views
    /partials          → layout condiviso (shell.php)
  routes.php
/config
  config.php           → bootstrap (env, error reporting, timezone)
/storage
  /videos
  /materials
  /certificates
/database
  schema.sql
  /migrations         → migrazioni incrementali per installazioni gia' esistenti
/tests
  google_meet_test.php → test del client Google (senza rete né credenziali reali)
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
```

Verifica il client Google senza rete e senza credenziali reali: genera una chiave RSA al volo,
controlla che la JWT sia firmata correttamente (verifica con la chiave pubblica) e che la
richiesta a Calendar contenga i parametri giusti, simulando le risposte di Google — compresi
gli errori 403 e 404.
