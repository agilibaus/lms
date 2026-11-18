-- ---------------------------------------------------
-- Privacy (18/11, Alessandro).
--
-- `privacy_versions`: il testo dell'informativa, scritto dall'Amministratore
-- in Impostazioni › Privacy. Una riga per versione pubblicata; la piu'
-- recente e' quella in vigore. Una correzione cambia il testo della versione
-- in vigore senza crearne una nuova.
--
-- `users.privacy_version_id`: l'ultima versione comunicata allo studente:
-- alla registrazione, con l'email «Il tuo accesso», o quando chiude
-- l'avviso di aggiornamento. Se e' piu' vecchia di quella in vigore,
-- sulla pagina dei corsi compare l'avviso.
--
-- `users.deletion_requested_at`: quando lo studente ha chiesto la
-- cancellazione dei suoi dati dal profilo. L'accesso si chiude subito
-- (is_active = 0) e l'Amministratore cancella l'account entro un mese.
--
-- Si puo' rieseguire: IF NOT EXISTS.
-- ---------------------------------------------------

CREATE TABLE IF NOT EXISTS privacy_versions (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    content_html    MEDIUMTEXT NOT NULL,
    published_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    corrected_at    DATETIME NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS privacy_version_id INT UNSIGNED NULL DEFAULT NULL AFTER last_seen_at,
    ADD COLUMN IF NOT EXISTS deletion_requested_at DATETIME NULL DEFAULT NULL AFTER privacy_version_id;
