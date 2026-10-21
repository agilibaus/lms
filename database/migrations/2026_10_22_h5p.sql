-- Questionari H5P (22/10, Alessandro): un questionario del modulo puo' essere
-- un contenuto interattivo H5P creato con Lumi, invece delle domande. Lo
-- studente lo svolge dentro Pistacchio e il punteggio diventa un tentativo
-- come gli altri: griglia, report, completamento e certificato non cambiano.
--
-- 1. `quizzes.kind`: 'domande' (come finora) o 'h5p'.
-- 2. `quizzes.h5p_dir`: la cartella del contenuto sotto storage/h5p, scelta
--    da Pistacchio (mai dal file caricato); `h5p_library` il tipo di
--    contenuto (per esempio «H5P.DragText 1.10»), `h5p_file` il nome del
--    file caricato, per riconoscerlo.
-- 3. `quiz_attempts.h5p_raw` e `h5p_max`: il punteggio come lo dice
--    l'esercizio («5 su 5»), accanto alla percentuale. Vuoti per i
--    questionari a domande.
--
-- Si puo' rieseguire: ogni colonna si aggiunge solo se manca.

SET @manca := (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'quizzes' AND COLUMN_NAME = 'kind');
SET @sql := IF(@manca,
    'ALTER TABLE quizzes ADD COLUMN kind ENUM(''domande'', ''h5p'') NOT NULL DEFAULT ''domande'' AFTER passing_score_pct,
                         ADD COLUMN h5p_dir VARCHAR(100) NULL DEFAULT NULL AFTER kind,
                         ADD COLUMN h5p_library VARCHAR(120) NULL DEFAULT NULL AFTER h5p_dir,
                         ADD COLUMN h5p_file VARCHAR(255) NULL DEFAULT NULL AFTER h5p_library',
    'DO 0');
PREPARE passo FROM @sql;
EXECUTE passo;
DEALLOCATE PREPARE passo;

SET @manca := (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'quiz_attempts' AND COLUMN_NAME = 'h5p_raw');
SET @sql := IF(@manca,
    'ALTER TABLE quiz_attempts ADD COLUMN h5p_raw DECIMAL(8,2) NULL DEFAULT NULL AFTER passed,
                               ADD COLUMN h5p_max DECIMAL(8,2) NULL DEFAULT NULL AFTER h5p_raw',
    'DO 0');
PREPARE passo FROM @sql;
EXECUTE passo;
DEALLOCATE PREPARE passo;
