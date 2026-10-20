-- «Tre parole» (21/10, Alessandro): alla fine di ogni modulo lo studente
-- scrive le prime tre parole che gli vengono (sensazioni, emozioni, stati
-- d'animo, pensieri, immagini). Le leggono il tutor del suo gruppo e
-- l'amministrazione, anonime o con il nome, come spunti per l'incontro.
--
-- 1. `courses.three_words_since`: da quando il corso le chiede. NULL = non
--    le chiede (la partenza). Si chiedono solo per i moduli completati da
--    quel momento in poi: le parole di un modulo finito settimane prima non
--    sarebbero piu' d'istinto.
-- 2. `module_words`: una riga per studente e modulo, con il gruppo che
--    aveva nel corso quando le ha scritte (decide quale tutor le legge).
--    Non si modificano: la chiave unica impedisce la seconda.
--
-- Si puo' rieseguire: la colonna si aggiunge solo se manca, la tabella solo
-- se non c'e'.

SET @manca := (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'courses' AND COLUMN_NAME = 'three_words_since');
SET @sql := IF(@manca,
    'ALTER TABLE courses ADD COLUMN three_words_since DATETIME NULL DEFAULT NULL AFTER position',
    'DO 0');
PREPARE passo FROM @sql;
EXECUTE passo;
DEALLOCATE PREPARE passo;

CREATE TABLE IF NOT EXISTS module_words (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    module_id       INT UNSIGNED NOT NULL,
    course_id       INT UNSIGNED NOT NULL,
    group_id        INT UNSIGNED NULL,
    word1           VARCHAR(30) NOT NULL,
    word2           VARCHAR(30) NOT NULL,
    word3           VARCHAR(30) NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_module (user_id, module_id),
    KEY idx_module_group (module_id, group_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (module_id) REFERENCES modules(id) ON DELETE CASCADE,
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
    FOREIGN KEY (group_id) REFERENCES `groups`(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
