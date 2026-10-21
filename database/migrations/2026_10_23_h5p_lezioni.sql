-- Contenuti H5P nelle lezioni (23/10, Alessandro, passo 3): una lezione puo'
-- avere piu' contenuti interattivi H5P creati con Lumi, oltre al
-- questionario del modulo. Chi scrive la lezione li mette dove vuole nel
-- testo, con un segnaposto dell'editor («Esercizio H5P»); quelli non messi
-- compaiono dopo il testo. Qualunque tipo di contenuto, anche senza
-- punteggio (Accordion, Timeline…), e nessun punteggio salvato: per lo
-- studente contano come «usati» per sbloccare «Segna come completata».
--
-- `h5p_dir` e' la cartella sotto storage/h5p, scelta da Pistacchio
-- («l{lezione}-{casuale}»); `h5p_library` il tipo, `h5p_title` il titolo
-- scritto in Lumi, `h5p_file` il nome del file caricato.
--
-- Si puo' rieseguire: la tabella si crea solo se manca.

CREATE TABLE IF NOT EXISTS lesson_h5p (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    lesson_id       INT UNSIGNED NOT NULL,
    h5p_dir         VARCHAR(100) NOT NULL,
    h5p_library     VARCHAR(120) NOT NULL,
    h5p_title       VARCHAR(200) NOT NULL DEFAULT '',
    h5p_file        VARCHAR(255) NOT NULL,
    position        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_lesson_h5p_order (lesson_id, position),
    FOREIGN KEY (lesson_id) REFERENCES lessons(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
