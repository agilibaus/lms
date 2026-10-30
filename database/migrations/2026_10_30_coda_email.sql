-- La coda delle email (30/10, Alessandro, strada C). Ci finiscono:
--   - le email a piu' persone (inviti, cambi di orario e annullamenti degli
--     incontri, domanda nuova agli admin, proposta in Bacheca, richiesta
--     d'iscrizione allo staff), che non tengono piu' ferma la pagina;
--   - le email a una persona sola che non sono partite subito (password
--     cambiata, risposta dell'esperto, iscrizione, corso completato);
--   - le email del cron (apertura dei moduli, conferma delle iscrizioni dello
--     staff, promemoria degli incontri), che cosi' sono segnate partite solo
--     a invio riuscito.
-- La svuota `bin/avvisi` a ogni giro (e `bin/rilascio-moduli` dopo le sue).
-- Una riga si cancella appena l'email parte; finche' `retry_until` non e'
-- passato si ritenta a ogni giro, dopo resta «non partita» e si vede in
-- «Impostazioni → Posta → Email in coda e non partite».
--
-- Gli inviti agli utenti importati restano fuori: contengono la password
-- provvisoria, che qui resterebbe salvata in chiaro.
--
-- Si puo' rieseguire.

CREATE TABLE IF NOT EXISTS email_queue (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    to_email        VARCHAR(255) NOT NULL,
    to_name         VARCHAR(255) NOT NULL DEFAULT '',
    subject         VARCHAR(500) NOT NULL,
    body            MEDIUMTEXT NOT NULL,
    reply_to        VARCHAR(255) NULL DEFAULT NULL,
    attachments     MEDIUMTEXT NULL DEFAULT NULL,
    dedupe_key      VARCHAR(190) NULL DEFAULT NULL,
    attempts        INT UNSIGNED NOT NULL DEFAULT 0,
    last_error      VARCHAR(500) NULL DEFAULT NULL,
    last_attempt_at DATETIME NULL DEFAULT NULL,
    retry_until     DATETIME NOT NULL,
    locked_until    DATETIME NULL DEFAULT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_email_queue_retry (retry_until),
    KEY idx_email_queue_dedupe (dedupe_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
