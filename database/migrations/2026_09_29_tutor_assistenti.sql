-- =====================================================
-- Migrazione incrementale — tutor e assistenti
-- Da eseguire solo su installazioni gia' esistenti.
-- =====================================================

-- Un assistente puo' affiancare piu' tutor contemporaneamente (deciso il
-- 28/09, pistacchio-lms.md §8.0). Il legame passa da una colonna su `users`,
-- che ne permetteva uno solo, a una tabella di legame.
CREATE TABLE IF NOT EXISTS assistant_tutors (
    assistant_id    INT UNSIGNED NOT NULL,
    tutor_id        INT UNSIGNED NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (assistant_id, tutor_id),
    INDEX idx_assistant_tutors_tutor (tutor_id),
    FOREIGN KEY (assistant_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (tutor_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- I legami esistenti non si perdono: ogni assistente continua ad affiancare il
-- tutor che aveva. La colonna users.supervising_tutor_id resta nelle
-- installazioni esistenti ma non la legge piu' nessuno.
INSERT IGNORE INTO assistant_tutors (assistant_id, tutor_id)
SELECT u.id, u.supervising_tutor_id
FROM users u
INNER JOIN users t ON t.id = u.supervising_tutor_id AND t.role = 'tutor'
WHERE u.role = 'assistente' AND u.supervising_tutor_id IS NOT NULL;

-- Gli assistenti li assegna solo l'admin: il tutor perde il permesso, e non lo
-- puo' riavere dalla pagina Permessi (il pannello lo rifiuta).
DELETE FROM role_permissions WHERE role = 'tutor' AND permission_key = 'assistant.manage';
