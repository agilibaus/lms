-- Rilascio progressivo dei moduli: data di apertura e notifiche gia' inviate.
--
-- `available_from` NULL vuol dire «sempre aperto», che e' il comportamento di
-- oggi: i moduli esistenti restano aperti senza che nessuno compili niente.
-- E' una DATETIME e non una DATE perche' un corso puo' voler aprire un modulo
-- alle 9 del mattino e non a mezzanotte.
--
-- L'indice serve al lavoro periodico, che ogni giorno cerca i moduli appena
-- aperti: senza, scorrerebbe tutta la tabella.

ALTER TABLE modules
    ADD COLUMN available_from DATETIME NULL DEFAULT NULL AFTER quiz_required,
    ADD INDEX idx_modules_available_from (available_from);

-- Una riga per ogni email di sblocco gia' mandata.
--
-- E' la memoria del lavoro periodico, e la chiave unica e' la garanzia che non
-- parta due volte: la riga si scrive PRIMA dell'invio, quindi nel peggiore dei
-- casi un'email si perde invece di arrivare doppia. E' il verso giusto in cui
-- sbagliare — una email mancata la si recupera, una raffica di doppioni la
-- vedono tutti gli studenti insieme.
--
-- Se l'amministratore sposta la data in avanti, le righe gia' scritte NON si
-- cancellano: l'email per quel modulo e' considerata mandata e non riparte.
-- Rimandarla vorrebbe dire scrivere a chi ha gia' ricevuto l'avviso.

CREATE TABLE module_unlock_notifications (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    module_id  INT UNSIGNED NOT NULL,
    user_id    INT UNSIGNED NOT NULL,
    sent_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_module_user (module_id, user_id),
    FOREIGN KEY (module_id) REFERENCES modules(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- La traccia dell'ultima esecuzione del lavoro periodico non ha bisogno di una
-- tabella: la scrive il comando stesso in `settings`, sotto la chiave
-- DRIP_LAST_RUN_AT, al termine di ogni giro. Serve perche' un cron che non gira
-- non si lamenta: senza, nessuno si accorgerebbe che le email hanno smesso di
-- partire, e il primo segnale sarebbe uno studente che non ha saputo di un
-- modulo aperto da due settimane.
