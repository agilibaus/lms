-- La pagina del gruppo si scalda (12/10, Alessandro).
--
-- «Il giro del cerchio»: l'admin o il tutor del gruppo pone una domanda al
-- gruppo, i partecipanti rispondono in una o due righe, e le risposte si
-- leggono sotto il cerchio. La domanda resta aperta finche' non ne arriva
-- una nuova; quelle passate, con le loro risposte, vanno in un archivio che
-- il gruppo puo' leggere. Il tutor (e l'admin) puo' nascondere una
-- risposta: resta visibile solo a chi l'ha scritta, non si cancella.
--
-- «Ci sarò»: per il prossimo incontro dal vivo del gruppo, chi ne fa parte
-- dice se ci sara', e la pagina mostra i volti di chi ha risposto di si'.
--
-- Si puo' rieseguire.

CREATE TABLE IF NOT EXISTS group_questions (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    group_id        INT UNSIGNED NOT NULL,
    author_id       INT UNSIGNED NULL,
    question        VARCHAR(300) NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_group_created (group_id, created_at),
    FOREIGN KEY (group_id) REFERENCES `groups`(id) ON DELETE CASCADE,
    FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS group_answers (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    question_id     INT UNSIGNED NOT NULL,
    user_id         INT UNSIGNED NOT NULL,
    answer          VARCHAR(200) NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NULL DEFAULT NULL,
    hidden_at       DATETIME NULL DEFAULT NULL,
    hidden_by       INT UNSIGNED NULL,
    UNIQUE KEY uq_question_user (question_id, user_id),
    FOREIGN KEY (question_id) REFERENCES group_questions(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (hidden_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS live_session_rsvps (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id      INT UNSIGNED NOT NULL,
    user_id         INT UNSIGNED NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_session_user (session_id, user_id),
    FOREIGN KEY (session_id) REFERENCES live_sessions(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
