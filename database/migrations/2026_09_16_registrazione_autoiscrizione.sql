-- =====================================================
-- Migrazione incrementale — registrazione autonoma e auto-iscrizione
-- Da eseguire solo su installazioni gia' esistenti.
-- =====================================================

-- Verifica dell'indirizzo email. Gli account gia' presenti sono stati creati
-- dallo staff: si considerano verificati, altrimenti resterebbero fuori.
ALTER TABLE users
    ADD COLUMN email_verified_at DATETIME NULL AFTER is_active;

UPDATE users SET email_verified_at = NOW() WHERE email_verified_at IS NULL;

-- Modalita' di iscrizione del corso. I corsi esistenti restano chiusi:
-- si aprono deliberatamente dal pannello, non per effetto di un aggiornamento.
ALTER TABLE courses
    ADD COLUMN enrollment_mode ENUM('open','request','closed') NOT NULL DEFAULT 'closed' AFTER is_published;

CREATE TABLE user_tokens (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    purpose         ENUM('email_verification','password_reset') NOT NULL,
    token_hash      CHAR(64) NOT NULL,
    expires_at      DATETIME NOT NULL,
    used_at         DATETIME NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_token_hash (token_hash),
    INDEX idx_user_purpose (user_id, purpose),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE enrollment_requests (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    course_id       INT UNSIGNED NOT NULL,
    status          ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    message         VARCHAR(500) NULL,
    requested_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    decided_at      DATETIME NULL,
    decided_by      INT UNSIGNED NULL,
    UNIQUE KEY uq_user_course_request (user_id, course_id),
    INDEX idx_course_status (course_id, status),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
    FOREIGN KEY (decided_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
