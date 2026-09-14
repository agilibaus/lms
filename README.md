# LMS — Vivere Mindfulness

LMS leggero e moderno in PHP puro + MySQL, pensato per sostituire Chamilo.

## Stack
- PHP 8.x (nessun framework), PDO con prepared statements
- MySQL/MariaDB
- Composer solo per autoload PSR-4 + poche librerie mirate (PDF, router)
- Frontend: CSS moderno (flexbox/grid, variabili CSS), vanilla JS o Alpine.js
- Design: asciutto, moderno, ispirato a Frappe LMS, mobile-first

## Funzionalità principali
- Corsi strutturati in Moduli → Lezioni (video + materiali scaricabili)
- Video via provider esterno (Bunny/Cloudflare Stream) o self-hosted
- Quiz con verifica automatica del punteggio
- Certificati di completamento generati in PDF
- Report/dashboard sui progressi (utente, corso, quiz)
- Ruoli: amministratore, tutor, assistente (assegnato a un tutor), studente — permessi configurabili in `role_permissions`
- Gruppi: classi/coorti e assegnazione corsi a gruppi interi
- Sessioni live integrate con Google Meet (via Google Calendar API)

## Stato del progetto
In sviluppo iniziale. Schema database definito in `database/schema.sql`.

## Struttura prevista

```
/public              → document root
/app
  /Controllers
  /Models
  /Views
  /Auth
  /Core
/storage
  /videos
  /materials
  /certificates
/database
  migrations.sql
```
