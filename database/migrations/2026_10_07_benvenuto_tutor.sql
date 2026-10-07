-- Il benvenuto del tutor all'inizio di un corso (07/10, chiesto da Elena).
--
-- Una foto a mezzo busto e un breve audio del tutor, con il testo scritto,
-- in cima alla pagina del corso. **Uno per tutor e per corso**: lo studente
-- sente il tutor del proprio gruppo, e un corso seguito da piu' gruppi con
-- tutor diversi ha piu' benvenuti. Li carica solo l'admin (permesso
-- `course.welcome`, sotto).
--
-- Rieseguirla non fa danni: `CREATE TABLE IF NOT EXISTS` e `INSERT IGNORE`.

CREATE TABLE IF NOT EXISTS course_tutor_welcomes (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    course_id       INT UNSIGNED NOT NULL,
    tutor_id        INT UNSIGNED NOT NULL,
    photo_path      VARCHAR(255) NOT NULL,
    audio_path      VARCHAR(255) NOT NULL,
    -- Il testo di quello che il tutor dice: senza, l'audio non e' accessibile
    -- a chi non sente o non puo' ascoltare in quel momento (WCAG 1.2.1).
    transcript      TEXT NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_course_tutor (course_id, tutor_id),
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
    FOREIGN KEY (tutor_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Quante volte uno studente ha aperto la pagina di un corso con un
-- benvenuto, e se l'ha ascoltato fino in fondo. Serve solo a decidere se
-- mostrarlo completo o ridotto a una riga: completo nelle prime tre visite,
-- ridotto dalla quarta **o** appena l'ha ascoltato, la prima delle due
-- (scelta di Elena). Una riga per studente e corso, non una per visita.
CREATE TABLE IF NOT EXISTS course_welcome_views (
    user_id         INT UNSIGNED NOT NULL,
    course_id       INT UNSIGNED NOT NULL,
    visits          INT UNSIGNED NOT NULL DEFAULT 0,
    listened_at     DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (user_id, course_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Solo l'admin carica i benvenuti. Il permesso si puo' spostare dalla
-- matrice dei permessi, come ogni altro.
INSERT IGNORE INTO role_permissions (role, permission_key) VALUES ('admin', 'course.welcome');
