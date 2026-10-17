-- La Bacheca (17/10, Alessandro): le risorse condivise di un corso.
--
-- Una bacheca per corso, divisa in categorie che crea l'admin da «Modifica
-- corso» (nome, colore, descrizione di 200 caratteri al piu', ordine). Le
-- risorse le pubblicano l'admin e i tutor del corso; gli studenti le
-- propongono, e la proposta resta in attesa finche' il tutor del gruppo
-- dello studente, o l'admin, non la pubblica o la scarta. Ognuno puo'
-- tenersi una risorsa («Questa me la tengo», privato) e raccontare in
-- poche righe come l'ha utilizzata; il racconto si pubblica subito e chi
-- modera lo puo' nascondere.
--
-- Le regole stanno in `App\Core\Bacheca` e `App\Auth\BachecaScope`.
--
-- Si puo' rieseguire.

CREATE TABLE IF NOT EXISTS board_categories (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    course_id       INT UNSIGNED NOT NULL,
    name            VARCHAR(80) NOT NULL,
    -- Una delle tavolozze del tema: verde, ardesia, terracotta, prugna.
    color           VARCHAR(20) NOT NULL DEFAULT 'verde',
    description     VARCHAR(200) NULL,
    position        INT NOT NULL DEFAULT 0,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_course_position (course_id, position),
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Una categoria con delle risorse non si elimina (lo rifiuta il pannello):
-- la cascata sulla categoria serve solo quando si elimina il corso intero.
CREATE TABLE IF NOT EXISTS board_resources (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    course_id       INT UNSIGNED NOT NULL,
    category_id     INT UNSIGNED NOT NULL,
    author_id       INT UNSIGNED NULL,
    title           VARCHAR(150) NOT NULL,
    kind            ENUM('scheda','checklist','video','podcast','articolo','altro') NOT NULL DEFAULT 'altro',
    url             VARCHAR(500) NULL,
    description     TEXT NOT NULL,
    file_path       VARCHAR(255) NULL,
    file_name       VARCHAR(255) NULL,
    file_size       INT UNSIGNED NULL,
    status          ENUM('pending','published','discarded') NOT NULL DEFAULT 'pending',
    featured        TINYINT(1) NOT NULL DEFAULT 0,
    decided_by      INT UNSIGNED NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    published_at    DATETIME NULL,
    KEY idx_course_status (course_id, status),
    KEY idx_category_status (category_id, status),
    KEY idx_author (author_id),
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES board_categories(id) ON DELETE CASCADE,
    FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (decided_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- «Questa me la tengo»: privato, nessuno vede chi o quanti.
CREATE TABLE IF NOT EXISTS board_saves (
    user_id         INT UNSIGNED NOT NULL,
    resource_id     INT UNSIGNED NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, resource_id),
    KEY idx_resource (resource_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (resource_id) REFERENCES board_resources(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- «Come l'ho utilizzata»: uno per persona e risorsa, modificabile.
CREATE TABLE IF NOT EXISTS board_uses (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    resource_id     INT UNSIGNED NOT NULL,
    user_id         INT UNSIGNED NOT NULL,
    body            VARCHAR(500) NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NULL DEFAULT NULL,
    hidden_at       DATETIME NULL DEFAULT NULL,
    hidden_by       INT UNSIGNED NULL,
    UNIQUE KEY uq_resource_user (resource_id, user_id),
    FOREIGN KEY (resource_id) REFERENCES board_resources(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (hidden_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Due permessi nuovi, spostabili dalla matrice come gli altri: l'admin
-- gestisce le categorie e tutte le bacheche, il tutor pubblica nei corsi
-- dei suoi gruppi e modera i contributi dei suoi studenti.
INSERT IGNORE INTO role_permissions (role, permission_key) VALUES
    ('admin', 'board.manage'),
    ('tutor', 'board.manage_own');
