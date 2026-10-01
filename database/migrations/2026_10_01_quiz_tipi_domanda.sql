-- =====================================================
-- Migrazione incrementale — domande a risposta multipla e aperta
-- Da eseguire solo su installazioni gia' esistenti.
-- =====================================================
--
-- Due tipi nuovi di domanda:
--
--   multiple_choice  piu' di una risposta corretta. Vale «tutto o niente»:
--                    il punto si prende selezionando esattamente tutte le
--                    corrette e nessuna sbagliata. Accanto alla domanda lo
--                    studente legge quante ne deve scegliere.
--
--   open             risposta scritta. **Non fa punteggio**: viene raccolta
--                    e letta dal tutor, non entra nel calcolo e non blocca
--                    i moduli successivi. Deciso con Elena il 01/10.
--
-- Perche' `quiz_attempt_answers` cambia. Finora ogni risposta era una riga
-- che puntava a un'opzione, e bastava: una domanda, una scelta. Adesso ci
-- sono due casi che quella forma non regge.
--
--   * Multipla: lo studente ne sceglie piu' d'una, quindi **piu' righe per
--     la stessa domanda**. La tabella lo permetteva gia', non c'e' niente da
--     cambiare.
--   * Aperta: non c'e' nessuna opzione da indicare, e il testo va messo da
--     qualche parte. Serve una colonna nuova, e `selected_option_id` deve
--     poter restare vuoto.
--
-- Rieseguirla non fa nulla: ogni passo si esegue solo se serve.

-- --- i due tipi nuovi nell'enumerazione -------------------------------
--
-- L'ordine dei valori e' quello in cui compaiono nel modulo, e i due vecchi
-- restano dove sono: un ENUM si confronta anche per posizione, e spostarli
-- cambierebbe il significato delle righe gia' scritte.

ALTER TABLE quiz_questions
    MODIFY COLUMN question_type
    ENUM('single_choice','true_false','multiple_choice','open')
    NOT NULL DEFAULT 'single_choice';

-- --- il testo della risposta aperta -----------------------------------

SET @colonna := (
    SELECT COLUMN_NAME FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'quiz_attempt_answers'
      AND COLUMN_NAME = 'answer_text'
);

SET @sql := IF(
    @colonna IS NULL,
    'ALTER TABLE quiz_attempt_answers ADD COLUMN answer_text TEXT NULL AFTER selected_option_id',
    'DO 0'
);
PREPARE passo FROM @sql; EXECUTE passo; DEALLOCATE PREPARE passo;

-- --- l'opzione scelta diventa facoltativa -----------------------------
--
-- La chiave esterna resta: dove c'e' un valore deve puntare a un'opzione
-- vera. Cambia solo che puo' non esserci, ed e' il caso della risposta
-- aperta. `ON DELETE CASCADE` sulla vecchia chiave andrebbe a cancellare la
-- riga quando si cancella l'opzione: si rifa' con `ON DELETE SET NULL`,
-- altrimenti correggere un'opzione di una domanda cancellerebbe le risposte
-- gia' date dagli studenti, che sono il dato da conservare.

SET @nullable := (
    SELECT IS_NULLABLE FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'quiz_attempt_answers'
      AND COLUMN_NAME = 'selected_option_id'
);

SET @vincolo := (
    SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'quiz_attempt_answers'
      AND COLUMN_NAME = 'selected_option_id'
      AND REFERENCED_TABLE_NAME IS NOT NULL
    LIMIT 1
);

SET @sql := IF(
    @nullable = 'NO' AND @vincolo IS NOT NULL,
    CONCAT('ALTER TABLE quiz_attempt_answers DROP FOREIGN KEY `', @vincolo, '`'),
    'DO 0'
);
PREPARE passo FROM @sql; EXECUTE passo; DEALLOCATE PREPARE passo;

SET @sql := IF(
    @nullable = 'NO',
    'ALTER TABLE quiz_attempt_answers MODIFY COLUMN selected_option_id INT UNSIGNED NULL',
    'DO 0'
);
PREPARE passo FROM @sql; EXECUTE passo; DEALLOCATE PREPARE passo;

SET @sql := IF(
    @nullable = 'NO',
    'ALTER TABLE quiz_attempt_answers
        ADD CONSTRAINT fk_risposta_opzione
        FOREIGN KEY (selected_option_id) REFERENCES quiz_options(id) ON DELETE SET NULL',
    'DO 0'
);
PREPARE passo FROM @sql; EXECUTE passo; DEALLOCATE PREPARE passo;
