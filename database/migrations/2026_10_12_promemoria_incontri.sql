-- Il promemoria degli incontri dal vivo (12/10, Alessandro): lo stesso
-- giorno, sette ore prima dell'inizio, a chi era stato invitato.
--
-- `live_sessions.invites_sent_at`: quando sono partiti gli inviti l'ultima
-- volta. Il promemoria parte solo per gli incontri con gli inviti gia'
-- mandati: senza invito un promemoria arriverebbe dal nulla. Gli incontri
-- che esistono gia' restano a NULL, quindi un incontro invitato prima di
-- questa migrazione non riceve il promemoria finche' non si rimandano gli
-- inviti.
--
-- `live_session_reminders`: a chi il promemoria e' gia' partito. La riga si
-- scrive prima dell'invio e la chiave unica impedisce i doppioni, come per
-- gli avvisi dei moduli (`module_unlock_notifications`).
--
-- Si puo' rieseguire.

SET @manca := (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'live_sessions' AND COLUMN_NAME = 'invites_sent_at');
SET @sql := IF(@manca,
    'ALTER TABLE live_sessions ADD COLUMN invites_sent_at DATETIME NULL DEFAULT NULL AFTER meet_link',
    'DO 0');
PREPARE passo FROM @sql;
EXECUTE passo;
DEALLOCATE PREPARE passo;

CREATE TABLE IF NOT EXISTS live_session_reminders (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id      INT UNSIGNED NOT NULL,
    user_id         INT UNSIGNED NOT NULL,
    sent_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_session_user (session_id, user_id),
    FOREIGN KEY (session_id) REFERENCES live_sessions(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
