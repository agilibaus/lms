-- Le domande degli studenti al tutor, e l'archivio delle risposte in fondo
-- al corso (07/10, chiesto da Elena).
--
-- Lo studente fa una domanda su un modulo del corso, o sul corso in
-- generale. La riceve il tutor del suo gruppo (assegnata qui, al momento
-- della domanda) e l'admin. Il tutor la puo' correggere e la pubblica con la
-- risposta, oppure la scarta; non ci sono risposte private. Le pubblicate le
-- vedono tutti gli iscritti al corso, divise per modulo e con la ricerca.
--
-- Si puo' rieseguire: `CREATE TABLE IF NOT EXISTS` e `INSERT IGNORE`.

CREATE TABLE IF NOT EXISTS course_questions (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    course_id       INT UNSIGNED NOT NULL,
    -- NULL: il corso in generale. Se il modulo viene eliminato la domanda
    -- resta, e passa al corso in generale.
    module_id       INT UNSIGNED NULL,
    -- NULL se lo studente viene eliminato: la domanda pubblicata resta, senza
    -- autore.
    student_id      INT UNSIGNED NULL,
    -- Il tutor del gruppo dello studente quando ha fatto la domanda. NULL:
    -- nessun tutor (iscritto dal catalogo), la vede solo l'admin.
    tutor_id        INT UNSIGNED NULL,
    question        TEXT NOT NULL,
    answer          TEXT NULL,
    status          ENUM('pending', 'published', 'discarded') NOT NULL DEFAULT 'pending',
    answered_by     INT UNSIGNED NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    answered_at     DATETIME NULL,
    KEY idx_course_status (course_id, status),
    KEY idx_tutor_status (tutor_id, status),
    KEY idx_student (student_id),
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
    FOREIGN KEY (module_id) REFERENCES modules(id) ON DELETE SET NULL,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (tutor_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (answered_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Come per il benvenuto: l'admin risponde a tutte, il tutor a quelle che gli
-- sono assegnate. Spostabili dalla matrice dei permessi.
INSERT IGNORE INTO role_permissions (role, permission_key) VALUES
    ('admin', 'question.answer'),
    ('tutor', 'question.answer_own');
