-- «Studenti fermi» (20/10, Alessandro): l'ultimo accesso alla piattaforma
-- e l'ultima visita a ciascun corso.
--
-- Il report si calcola dalle attivita' che lasciano gia' una data (lezioni
-- completate, video, questionari, presenze, domande, Bacheca). Queste due
-- date servono a distinguere chi apre il corso ma non avanza da chi non lo
-- apre piu', e a dire nel dettaglio dello studente quando e' entrato
-- l'ultima volta. Si scrivono al massimo una volta ogni cinque minuti per
-- persona (e per corso), non a ogni pagina.
--
-- Si registra solo la data dell'ultima volta, non ogni accesso: basta a
-- decidere chi contattare, il resto sarebbe un registro di abitudini che
-- nessuno ha chiesto.
--
-- Partono vuote: per chi e' gia' iscritto il dato arriva con il primo
-- accesso dopo questa migrazione.
--
-- Si puo' rieseguire: le colonne si aggiungono solo se mancano.

SET @manca := (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'last_seen_at');
SET @sql := IF(@manca,
    'ALTER TABLE users ADD COLUMN last_seen_at DATETIME NULL DEFAULT NULL AFTER calendar_token_used_at',
    'DO 0');
PREPARE passo FROM @sql;
EXECUTE passo;
DEALLOCATE PREPARE passo;

SET @manca := (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'enrollments' AND COLUMN_NAME = 'last_visit_at');
SET @sql := IF(@manca,
    'ALTER TABLE enrollments ADD COLUMN last_visit_at DATETIME NULL DEFAULT NULL AFTER confirmation_pending',
    'DO 0');
PREPARE passo FROM @sql;
EXECUTE passo;
DEALLOCATE PREPARE passo;
