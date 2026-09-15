<p align="center"><img src="https://github.com/agilibaus/lms/blob/main/pistacchio_icon.png" title="Logo Pistacchio LMS" width="150" height="150"></p>

# Pistacchio LMS

Learning Management System leggero e moderno in PHP puro + MySQL.

## Stack
- PHP 8.1+ (nessun framework), PDO con prepared statements
- MySQL/MariaDB
- Composer per l'autoload PSR-4 + Dompdf (generazione dei certificati PDF)
- Frontend: CSS moderno (flexbox/grid, variabili CSS), nessuna dipendenza JS pesante
- Design: asciutto, moderno, ispirato a Frappe LMS, mobile-first (sidebar a drawer sotto i 768px, via checkbox CSS senza JS)

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
- ⏳ Gestione gruppi e permessi da pannello admin
- ⏳ Integrazione Google Meet

## Requisiti

- PHP **8.1 o superiore**, con estensioni `pdo_mysql`, `mbstring`, `dom`, `gd` (le ultime due richieste da Dompdf per i certificati)
- MySQL 8+ o MariaDB 10.6+
- Server web con supporto al rewrite degli URL (Apache + `mod_rewrite`, oppure Nginx configurato in modo equivalente)
- Composer (autoload PSR-4 e installazione di Dompdf: senza `composer install` i certificati non possono essere generati)
- Per le sessioni live: un progetto Google Cloud con **Calendar API** abilitata e un account di servizio (o credenziali OAuth) con accesso al calendario da usare per generare i link Meet

## Installazione

1. **Clona il repository**
   ```bash
   git clone https://github.com/agilibaus/lms.git
   cd lms
   ```

2. **Installa le dipendenze**
   ```bash
   composer update
   ```
   Installa l'autoload PSR-4 e **Dompdf** (certificati PDF). Usa `composer update` e non
   `composer install`: `composer.lock` viene rigenerato includendo Dompdf, aggiunto in questa
   fase. Dagli aggiornamenti successivi `composer install` è di nuovo sufficiente.

3. **Configura l'ambiente**
   ```bash
   cp .env.example .env
   ```
   Modifica `.env` con le credenziali del tuo database:
   ```
   DB_HOST=127.0.0.1
   DB_NAME=lms
   DB_USER=il_tuo_utente
   DB_PASS=la_tua_password
   APP_DEBUG=0
   APP_URL=https://lms.example.com
   ```
   `APP_URL` viene usato nel PDF del certificato per comporre il link di verifica pubblica.

4. **Crea il database e importa lo schema**
   ```bash
   mysql -u il_tuo_utente -p -e "CREATE DATABASE lms CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
   mysql -u il_tuo_utente -p lms < database/schema.sql
   ```

   Se hai gia' un'installazione creata con una versione precedente dello schema, applica
   invece le migrazioni incrementali presenti in `database/migrations/` (in ordine di data):
   ```bash
   mysql -u il_tuo_utente -p lms < database/migrations/2026_09_15_quiz_certificates.sql
   ```

5. **Imposta il document root sulla cartella `public/`**
   L'applicazione deve essere servita con `public/` come document root (non la root del repo), così i file applicativi in `app/`, `config/`, `database/` restano fuori dall'accesso diretto via browser.

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

   In alternativa, per un test rapido in locale senza Apache:
   ```bash
   php -S localhost:8000 -t public
   ```

6. **Permessi sulla cartella storage**
   ```bash
   chmod -R 775 storage/
   ```
   Se prevedi upload di materiali/video di dimensioni consistenti, alza anche i limiti PHP
   (`php.ini` o `.htaccess`): `upload_max_filesize`, `post_max_size`, `max_execution_time`.
   Per i video preferisci comunque Bunny/Cloudflare Stream: l'upload self-hosted è pensato
   per file di piccole dimensioni e non per lo storage di produzione.

7. **Crea il primo utente amministratore**
   Non c'è ancora un pannello di registrazione: per il primo admin, inserisci manualmente una riga in `users` con una password hashata:
   ```bash
   php -r "echo password_hash('la-tua-password', PASSWORD_DEFAULT), PHP_EOL;"
   ```
   ```sql
   INSERT INTO users (email, password_hash, full_name, role)
   VALUES ('admin@example.com', '<hash generato sopra>', 'Nome Cognome', 'admin');
   ```

8. **Verifica**
   Apri l'URL configurato (es. `http://lms.local` o `http://localhost:8000`): dovresti vedere la pagina di login.

## Struttura del progetto

```
/public              → document root
  /assets/css         → stylesheet
  index.php           → front controller
  .htaccess           → rewrite verso index.php
/app
  /Controllers        → logica delle route (AuthController, CourseController, ModuleController, LessonController, QuizController, CertificateController, ReportController)
  /Models             → accesso dati via PDO/query preparate (UserModel, CourseModel, ModuleModel, LessonModel, Quiz*, CertificateModel, GroupModel, ReportModel...)
  /Auth               → login, sessione, permessi per ruolo (Auth.php)
  /Core               → Router minimale, Database (PDO), Env, View, Upload, VideoEmbed, CourseAccess, CertificateService, Csv
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
