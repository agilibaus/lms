<div style="width:250; margin:0 auto"><img src="https://github.com/agilibaus/lms/blob/main/pistacchio_icon.png" title="Logo Pistacchio LMS" width="250" height="250"></div>

# Pistacchio LMS

Learning Management System leggero e moderno in PHP puro + MySQL.

## Stack
- PHP 8.1+ (nessun framework), PDO con prepared statements
- MySQL/MariaDB
- Composer solo per autoload PSR-4 + poche librerie mirate (PDF, in arrivo)
- Frontend: CSS moderno (flexbox/grid, variabili CSS), nessuna dipendenza JS pesante
- Design: asciutto, moderno, arioso, mobile-first (sidebar a drawer sotto i 768px, via checkbox CSS senza JS)

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
- ⏳ Moduli/lezioni con upload materiali ed embed video
- ⏳ Quiz, certificati PDF, report
- ⏳ Gestione gruppi e permessi da pannello admin
- ⏳ Integrazione Google Meet

## Requisiti

- PHP **8.1 o superiore**, con estensioni `pdo_mysql`, `mbstring`
- MySQL 8+ o MariaDB 10.6+
- Server web con supporto al rewrite degli URL (Apache + `mod_rewrite`, oppure Nginx configurato in modo equivalente)
- Composer (per l'autoload PSR-4; nessuna dipendenza esterna obbligatoria per l'MVP attuale)
- Per le sessioni live: un progetto Google Cloud con **Calendar API** abilitata e un account di servizio (o credenziali OAuth) con accesso al calendario da usare per generare i link Meet

## Installazione

1. **Clona il repository**
   ```bash
   git clone https://github.com/agilibaus/lms.git
   cd lms
   ```

2. **Installa le dipendenze**
   ```bash
   composer install
   ```

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
   ```

4. **Crea il database e importa lo schema**
   ```bash
   mysql -u il_tuo_utente -p -e "CREATE DATABASE lms CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
   mysql -u il_tuo_utente -p lms < database/schema.sql
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
  /Controllers
  /Core               → Router, Database (PDO), Auth, Env, View
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
```
