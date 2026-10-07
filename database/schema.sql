-- =====================================================
-- LMS Schema — MySQL 8+
-- Corsi video + materiali, quiz, certificati, report
-- =====================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------
-- Utenti
-- ---------------------------------------------------
CREATE TABLE users (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email           VARCHAR(190) NOT NULL UNIQUE,
    contact_email   VARCHAR(255) NULL,       -- l'email che il tutor da' ai suoi studenti (benvenuto del corso)
    password_hash   VARCHAR(255) NOT NULL,
    -- Quando la password e' stata cambiata l'ultima volta. La sessione ne
    -- tiene una copia presa all'accesso: se le due non combaciano piu', la
    -- sessione e' stata aperta con la password vecchia e viene chiusa.
    password_changed_at DATETIME NULL,
    -- 1 quando la password l'ha generata un admin: finche' resta 1 l'utente
    -- vede solo la pagina di cambio password.
    must_change_password TINYINT(1) NOT NULL DEFAULT 0,
    -- Quando ha visto il video di benvenuto. NULL = non ancora: al primo
    -- accesso viene portato alla pagina del benvenuto, una volta sola e solo
    -- se un video e' configurato. Si puo' comunque rivedere dal profilo.
    welcome_seen_at DATETIME NULL DEFAULT NULL,
    -- 1 quando l'account nasce da un'importazione e l'email con la password
    -- non e' ancora partita. La manda `bin/invita-utenti`, a scaglioni.
    -- Nessuna password viene conservata in attesa: si genera al momento
    -- dell'invio.
    invite_pending TINYINT(1) NOT NULL DEFAULT 0,
    full_name       VARCHAR(150) NOT NULL,
    role            ENUM('admin','tutor','assistente','studente') NOT NULL DEFAULT 'studente',
    -- assistente e' assegnato "sotto" un tutor (aiuta il tutor, non l'admin)
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    -- NULL = indirizzo non ancora confermato: l'utente non puo' accedere
    -- (gli account creati da admin/tutor e dall'installer nascono gia' verificati)
    email_verified_at DATETIME NULL,
    -- Profilo compilato dall'utente
    bio             TEXT NULL,
    phone           VARCHAR(40) NULL,
    city            VARCHAR(120) NULL,
    -- Percorso relativo a /storage (mai un URL pubblico)
    avatar_path     VARCHAR(255) NULL,
    -- Indirizzo personale del calendario (.ics da sottoscrivere). NULL
    -- finche' non lo si chiede: un segreto mai creato non si puo' rubare.
    -- E' una credenziale, non un identificativo: chi ha il link vede gli
    -- impegni di questa persona senza fare l'accesso. Si rigenera dal
    -- profilo, e rigenerarlo invalida il link vecchio.
    calendar_token  VARCHAR(64) NULL DEFAULT NULL UNIQUE,
    -- Quando e' stato creato e quando e' stato letto l'ultima volta: una
    -- credenziale di cui non si sa niente non si sa nemmeno quando
    -- revocarla. Non si registra CHI ha letto: la data basta a decidere,
    -- il resto sarebbe un registro di abitudini che nessuno ha chiesto.
    calendar_token_created_at DATETIME NULL DEFAULT NULL,
    calendar_token_used_at    DATETIME NULL DEFAULT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------
-- Permessi per ruolo (configurabili senza toccare il codice)
-- ---------------------------------------------------
CREATE TABLE role_permissions (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    role            ENUM('admin','tutor','assistente','studente') NOT NULL,
    permission_key  VARCHAR(100) NOT NULL,   -- es. 'course.create', 'course.edit', 'quiz.grade', 'user.manage', 'group.manage', 'report.view'
    UNIQUE KEY uq_role_permission (role, permission_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Permessi di default (personalizzabili in seguito da pannello admin)
INSERT INTO role_permissions (role, permission_key) VALUES
    ('admin','course.create'), ('admin','course.edit'), ('admin','course.delete'),
    ('admin','user.manage'), ('admin','group.manage'), ('admin','quiz.grade'),
    ('admin','report.view'), ('admin','certificate.issue'),
    ('admin','settings.manage'), ('admin','course.welcome'),
    ('tutor','course.edit'), ('tutor','quiz.grade'), ('tutor','report.view'),
    ('tutor','group.manage_own'), ('tutor','course.welcome_own'),
    ('assistente','quiz.grade_assigned'), ('assistente','report.view_assigned'),
    ('studente','course.view'), ('studente','quiz.take'), ('studente','certificate.view_own');

-- ---------------------------------------------------
-- Gruppi (classi/coorti + assegnazione corsi a gruppi)
-- ---------------------------------------------------
-- ---------------------------------------------------
-- Impostazioni modificabili dal pannello
-- ---------------------------------------------------
-- Chiavi con gli stessi nomi delle variabili del .env: se manca la riga,
-- vale il file. Svuotare un valore dal pannello restituisce il comando al .env.
CREATE TABLE settings (
    setting_key     VARCHAR(100) NOT NULL PRIMARY KEY,
    setting_value   TEXT NULL,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by      INT UNSIGNED NULL,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Assistenti e tutor che affiancano: un assistente puo' affiancare piu' tutor.
-- Li assegna solo l'admin.
CREATE TABLE assistant_tutors (
    assistant_id    INT UNSIGNED NOT NULL,
    tutor_id        INT UNSIGNED NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (assistant_id, tutor_id),
    INDEX idx_assistant_tutors_tutor (tutor_id),
    FOREIGN KEY (assistant_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (tutor_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `groups` (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(150) NOT NULL,
    description     TEXT,
    whatsapp_url    VARCHAR(255) NULL,       -- link di invito al gruppo WhatsApp (benvenuto del corso)
    -- Logo del gruppo: PNG quadrato in /storage/group-logos, servito da
    -- /admin/groups/{id}/logo. NULL = riquadro con le iniziali.
    logo_path       VARCHAR(255),
    tutor_id        INT UNSIGNED NULL,          -- tutor responsabile del gruppo/coorte
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tutor_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE group_members (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    group_id        INT UNSIGNED NOT NULL,
    user_id         INT UNSIGNED NOT NULL,
    joined_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_group_user (group_id, user_id),
    FOREIGN KEY (group_id) REFERENCES `groups`(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Corsi assegnati a un intero gruppo (in aggiunta alle iscrizioni individuali in 'enrollments')
CREATE TABLE group_course_access (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    group_id        INT UNSIGNED NOT NULL,
    course_id       INT UNSIGNED NOT NULL,
    granted_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_group_course (group_id, course_id),
    FOREIGN KEY (group_id) REFERENCES `groups`(id) ON DELETE CASCADE,
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------
-- Corsi
-- ---------------------------------------------------
CREATE TABLE courses (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title           VARCHAR(200) NOT NULL,
    slug            VARCHAR(220) NOT NULL UNIQUE,
    description     TEXT,
    -- Percorso relativo a /storage della copertina (misura grande); accanto sta
    -- la misura piccola con lo stesso nome piu' "-card". Un valore http(s)://
    -- viene invece usato come indirizzo esterno.
    cover_image     VARCHAR(255),
    -- Testo alternativo dell'immagine; se vuoto la vista usa il titolo.
    cover_alt       VARCHAR(255),
    is_published    TINYINT(1) NOT NULL DEFAULT 0,
    -- come ci si iscrive: 'open' iscrizione immediata dal catalogo,
    -- 'request' richiesta da approvare, 'closed' solo admin/tutor o gruppi
    enrollment_mode ENUM('open','request','closed') NOT NULL DEFAULT 'closed',
    -- Ordine deciso da chi amministra: vale nell'elenco dei corsi, in Gestione
    -- corsi e nel catalogo degli studenti.
    position        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_by      INT UNSIGNED NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------
-- Moduli (raggruppano le lezioni all'interno di un corso)
-- ---------------------------------------------------
CREATE TABLE modules (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    course_id       INT UNSIGNED NOT NULL,
    title           VARCHAR(200) NOT NULL,
    position        INT UNSIGNED NOT NULL DEFAULT 0,
    -- se 1, i moduli successivi restano bloccati finche' il quiz di questo modulo non e' superato
    quiz_required   TINYINT(1) NOT NULL DEFAULT 0,
    -- vuoto = sempre aperto; con una data il modulo si apre in quel momento (§8.7)
    available_from  DATETIME NULL DEFAULT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
    INDEX idx_course_position (course_id, position),
    INDEX idx_modules_available_from (available_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Un'email di sblocco gia' mandata. La riga si scrive PRIMA dell'invio:
-- la chiave unica e' cio' che impedisce i doppioni.
CREATE TABLE module_unlock_notifications (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    module_id  INT UNSIGNED NOT NULL,
    user_id    INT UNSIGNED NOT NULL,
    sent_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_module_user (module_id, user_id),
    FOREIGN KEY (module_id) REFERENCES modules(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------
-- Lezioni (video + materiali scaricabili)
-- ---------------------------------------------------
CREATE TABLE lessons (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    module_id       INT UNSIGNED NOT NULL,
    title           VARCHAR(200) NOT NULL,
    content_html    TEXT,                       -- testo/istruzioni della lezione
    video_provider  ENUM('bunny','cloudflare','self_hosted','none') NOT NULL DEFAULT 'none',
    video_ref       VARCHAR(255),                -- video ID o percorso file
    duration_seconds INT UNSIGNED DEFAULT 0,
    position        INT UNSIGNED NOT NULL DEFAULT 0,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (module_id) REFERENCES modules(id) ON DELETE CASCADE,
    INDEX idx_module_position (module_id, position)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Materiali scaricabili collegati a una lezione (PDF, audio, ecc.)
CREATE TABLE lesson_materials (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    lesson_id       INT UNSIGNED NOT NULL,
    file_name       VARCHAR(255) NOT NULL,
    file_path       VARCHAR(255) NOT NULL,
    file_type       VARCHAR(50),
    file_size_bytes INT UNSIGNED,
    position        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (lesson_id) REFERENCES lessons(id) ON DELETE CASCADE,
    INDEX idx_lesson_materials_order (lesson_id, position)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------
-- Iscrizioni
-- ---------------------------------------------------
CREATE TABLE enrollments (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    course_id       INT UNSIGNED NOT NULL,
    enrolled_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at    DATETIME NULL,
    progress_pct    DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    UNIQUE KEY uq_user_course (user_id, course_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tracciamento completamento singola lezione
CREATE TABLE lesson_progress (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    lesson_id       INT UNSIGNED NOT NULL,
    completed_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_lesson (user_id, lesson_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (lesson_id) REFERENCES lessons(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Da dove riprende lo studente. Una riga per studente e lezione, sovrascritta:
-- sta in tabella e non nel browser perche' la ripresa deve funzionare anche
-- cambiando dispositivo.
CREATE TABLE lesson_video_progress (
    user_id          INT UNSIGNED NOT NULL,
    lesson_id        INT UNSIGNED NOT NULL,
    position_seconds INT UNSIGNED NOT NULL DEFAULT 0,
    duration_seconds INT UNSIGNED NULL,       -- durata dichiarata dal player
    first_seen_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, lesson_id),
    INDEX idx_lezione (lesson_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (lesson_id) REFERENCES lessons(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Quali parti del video ha guardato. Gli intervalli arrivano gia' uniti:
-- chi guarda una lezione intera lascia una riga, non una al minuto. Da qui
-- escono la percentuale vista e il tempo guardato del rendiconto.
-- `end_seconds` e' escluso, cosi' due intervalli che si toccano si uniscono
-- senza contare due volte il secondo di confine.
CREATE TABLE lesson_video_intervals (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id       INT UNSIGNED NOT NULL,
    lesson_id     INT UNSIGNED NOT NULL,
    start_seconds INT UNSIGNED NOT NULL,
    end_seconds   INT UNSIGNED NOT NULL,
    recorded_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_studente_lezione (user_id, lesson_id, start_seconds),
    INDEX idx_lezione (lesson_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (lesson_id) REFERENCES lessons(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------
-- Quiz
-- ---------------------------------------------------
CREATE TABLE quizzes (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    module_id       INT UNSIGNED NOT NULL,
    title           VARCHAR(200) NOT NULL,
    passing_score_pct INT UNSIGNED NOT NULL DEFAULT 70,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (module_id) REFERENCES modules(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE quiz_questions (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    quiz_id         INT UNSIGNED NOT NULL,
    question_text   TEXT NOT NULL,
    -- multiple_choice: piu' risposte corrette, vale «tutto o niente».
    -- open: risposta scritta, raccolta ma non valutata (vedi §8 del promemoria).
    question_type   ENUM('single_choice','true_false','multiple_choice','open') NOT NULL DEFAULT 'single_choice',
    position        INT UNSIGNED NOT NULL DEFAULT 0,
    FOREIGN KEY (quiz_id) REFERENCES quizzes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE quiz_options (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    question_id     INT UNSIGNED NOT NULL,
    option_text     VARCHAR(500) NOT NULL,
    is_correct      TINYINT(1) NOT NULL DEFAULT 0,
    position        INT UNSIGNED NOT NULL DEFAULT 0,
    FOREIGN KEY (question_id) REFERENCES quiz_questions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE quiz_attempts (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    quiz_id         INT UNSIGNED NOT NULL,
    score_pct       DECIMAL(5,2) NOT NULL,
    passed          TINYINT(1) NOT NULL DEFAULT 0,
    attempted_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (quiz_id) REFERENCES quizzes(id) ON DELETE CASCADE,
    INDEX idx_user_quiz (user_id, quiz_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Una riga per opzione scelta: la domanda a risposta multipla ne produce
-- piu' d'una per la stessa domanda. La risposta aperta non punta a nessuna
-- opzione e porta il proprio testo in `answer_text`.
--
-- `ON DELETE SET NULL` sull'opzione e non CASCADE: correggere un'opzione di
-- una domanda non deve cancellare le risposte gia' date dagli studenti.
CREATE TABLE quiz_attempt_answers (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    attempt_id      INT UNSIGNED NOT NULL,
    question_id     INT UNSIGNED NOT NULL,
    selected_option_id INT UNSIGNED NULL,
    answer_text     TEXT NULL,
    is_correct      TINYINT(1) NOT NULL,
    FOREIGN KEY (attempt_id) REFERENCES quiz_attempts(id) ON DELETE CASCADE,
    FOREIGN KEY (question_id) REFERENCES quiz_questions(id) ON DELETE CASCADE,
    FOREIGN KEY (selected_option_id) REFERENCES quiz_options(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------
-- Certificati
-- ---------------------------------------------------
CREATE TABLE certificates (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    course_id       INT UNSIGNED NOT NULL,
    certificate_code VARCHAR(40) NOT NULL UNIQUE,   -- codice per verifica pubblica
    file_path       VARCHAR(255) NOT NULL,
    issued_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    issued_by       INT UNSIGNED NULL,               -- NULL = emissione automatica del sistema
    revoked_at      DATETIME NULL,                   -- valorizzato = certificato revocato (non ri-emesso in automatico)
    revoked_reason  VARCHAR(255) NULL,
    UNIQUE KEY uq_user_course_cert (user_id, course_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
    FOREIGN KEY (issued_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------
-- Token monouso: verifica indirizzo email e reset password
-- ---------------------------------------------------
CREATE TABLE user_tokens (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    purpose         ENUM('email_verification','password_reset') NOT NULL,
    -- in tabella finisce solo l'hash: chi legge il database non puo' usare i token
    token_hash      CHAR(64) NOT NULL,
    expires_at      DATETIME NOT NULL,
    used_at         DATETIME NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_token_hash (token_hash),
    INDEX idx_user_purpose (user_id, purpose),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------
-- Richieste di iscrizione (corsi con enrollment_mode = 'request')
-- ---------------------------------------------------
CREATE TABLE enrollment_requests (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    course_id       INT UNSIGNED NOT NULL,
    status          ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    message         VARCHAR(500) NULL,             -- due righe di presentazione dello studente
    requested_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    decided_at      DATETIME NULL,
    decided_by      INT UNSIGNED NULL,
    UNIQUE KEY uq_user_course_request (user_id, course_id),
    INDEX idx_course_status (course_id, status),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
    FOREIGN KEY (decided_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------
-- Sessioni (login persistente / "ricordami")
-- ---------------------------------------------------
CREATE TABLE remember_tokens (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    token_hash      VARCHAR(255) NOT NULL,
    expires_at      DATETIME NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------
-- Sessioni live (Google Meet)
-- ---------------------------------------------------
CREATE TABLE live_sessions (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    module_id       INT UNSIGNED NULL,          -- sessione legata a un modulo di corso...
    group_id        INT UNSIGNED NULL,          -- ...e/o a un gruppo specifico (almeno uno dei due valorizzato)
    title           VARCHAR(200) NOT NULL,
    description     TEXT,
    starts_at       DATETIME NOT NULL,
    ends_at         DATETIME NOT NULL,
    google_event_id VARCHAR(255),                -- id evento Google Calendar (per update/cancel successivi)
    meet_link       VARCHAR(255),
    created_by      INT UNSIGNED NOT NULL,       -- tutor/admin che ha creato la sessione
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (module_id) REFERENCES modules(id) ON DELETE CASCADE,
    FOREIGN KEY (group_id) REFERENCES `groups`(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Presenze alle sessioni live (per il report)
CREATE TABLE live_session_attendance (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id      INT UNSIGNED NOT NULL,
    user_id         INT UNSIGNED NOT NULL,
    joined_at       DATETIME NULL,
    -- 'platform': ingresso tracciato dal link interno; 'manual': segnato dal tutor
    source          ENUM('platform','manual') NOT NULL DEFAULT 'platform',
    UNIQUE KEY uq_session_user (session_id, user_id),
    FOREIGN KEY (session_id) REFERENCES live_sessions(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Il benvenuto del tutor all'inizio di un corso, e le visite che decidono se
-- mostrarlo completo o ridotto (migrazione 2026_10_07_benvenuto_tutor.sql).
CREATE TABLE course_tutor_welcomes (
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
CREATE TABLE course_welcome_views (
    user_id         INT UNSIGNED NOT NULL,
    course_id       INT UNSIGNED NOT NULL,
    visits          INT UNSIGNED NOT NULL DEFAULT 0,
    listened_at     DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (user_id, course_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;
