-- =====================================================
-- Migrazione incrementale — ripresa del video e fruizione
-- Da eseguire solo su installazioni gia' esistenti.
-- =====================================================
--
-- Due tabelle, perche' rispondono a due domande diverse.
--
--   lesson_video_progress   «da dove riprende questo studente?»
--                           Una riga per studente e lezione, sovrascritta.
--                           Serve alla ripresa, anche cambiando dispositivo:
--                           e' la ragione per cui questo dato sta qui e non
--                           nel browser.
--
--   lesson_video_intervals  «quali parti ha guardato?»
--                           Gli intervalli visti, gia' uniti in scrittura:
--                           chi guarda una lezione dall'inizio alla fine
--                           lascia una riga sola, non una ogni minuto. Da qui
--                           si ricava la percentuale vista e il tempo
--                           guardato, che vanno nel rendiconto per Regione
--                           Lombardia.
--
-- Perche' gli intervalli e non un totale. Un totale si gonfia riguardando
-- dieci volte lo stesso minuto; gli intervalli dicono quanta lezione e'
-- stata effettivamente vista, che e' il dato che serve rendicontare.
--
-- Gli orari li mette il server (`recorded_at`, `updated_at`), mai il browser:
-- il registro deve poter essere ripercorso da chi controlla.
--
-- Rieseguirla non fa nulla: le tabelle si creano solo se mancano.

CREATE TABLE IF NOT EXISTS lesson_video_progress (
    user_id          INT UNSIGNED NOT NULL,
    lesson_id        INT UNSIGNED NOT NULL,
    -- Ultimo secondo raggiunto: e' da qui che riparte «Riprendi».
    position_seconds INT UNSIGNED NOT NULL DEFAULT 0,
    -- Durata dichiarata dal player. Serve a calcolare la percentuale anche
    -- quando `lessons.duration_seconds` non e' stata compilata a mano.
    duration_seconds INT UNSIGNED NULL,
    first_seen_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, lesson_id),
    INDEX idx_lezione (lesson_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (lesson_id) REFERENCES lessons(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS lesson_video_intervals (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id       INT UNSIGNED NOT NULL,
    lesson_id     INT UNSIGNED NOT NULL,
    -- Estremi in secondi dall'inizio del video. `end_seconds` e' escluso,
    -- cosi' due intervalli che si toccano si uniscono senza contare due
    -- volte il secondo di confine.
    start_seconds INT UNSIGNED NOT NULL,
    end_seconds   INT UNSIGNED NOT NULL,
    recorded_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- Gli intervalli di uno studente su una lezione si leggono sempre tutti
    -- insieme, in ordine: e' l'indice che serve all'unione e al report.
    INDEX idx_studente_lezione (user_id, lesson_id, start_seconds),
    INDEX idx_lezione (lesson_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (lesson_id) REFERENCES lessons(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
