-- I pagamenti ricevuti da Stripe (06/11, seconda patch dei corsi a
-- pagamento). Una riga per pagamento, scritta quando arriva l'avviso firmato
-- di Stripe: con l'esito, «iscritto» o «non_riuscito» con il motivo.
--
--   stripe_event_id    l'avviso: lo stesso avviso ripetuto si conta una volta;
--   stripe_session_id  il pagamento: due avvisi dello stesso pagamento (per
--                      esempio un bonifico confermato dopo) si contano una volta;
--   course_id, user_id riconosciuti dall'identificativo del link (plink_...) e
--                      dal numero dello studente; NULL se non riconosciuti;
--   email              quella scritta su Stripe, per riconoscere chi ha pagato;
--   amount_total       quanto ha pagato, in centesimi, dopo gli sconti;
--   amount_subtotal    il prezzo prima degli sconti, confrontato con il corso.
--
-- Si puo' rieseguire.

CREATE TABLE IF NOT EXISTS payments (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    stripe_event_id   VARCHAR(255) NOT NULL,
    stripe_session_id VARCHAR(255) NOT NULL,
    livemode          TINYINT(1) NOT NULL DEFAULT 0,
    course_id         INT UNSIGNED NULL DEFAULT NULL,
    user_id           INT UNSIGNED NULL DEFAULT NULL,
    email             VARCHAR(255) NULL DEFAULT NULL,
    amount_total      INT NOT NULL DEFAULT 0,
    amount_subtotal   INT NOT NULL DEFAULT 0,
    currency          VARCHAR(10) NOT NULL DEFAULT '',
    esito             ENUM('iscritto','non_riuscito') NOT NULL,
    motivo            VARCHAR(255) NULL DEFAULT NULL,
    received_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_payments_event (stripe_event_id),
    UNIQUE KEY uq_payments_session (stripe_session_id),
    KEY idx_payments_course (course_id, received_at),
    CONSTRAINT fk_payments_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE SET NULL,
    CONSTRAINT fk_payments_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
