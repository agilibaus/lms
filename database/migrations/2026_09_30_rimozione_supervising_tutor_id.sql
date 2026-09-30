-- =====================================================
-- Migrazione incrementale — rimozione di users.supervising_tutor_id
-- Da eseguire solo su installazioni gia' esistenti.
-- =====================================================
--
-- Il legame fra assistente e tutor e' passato alla tabella `assistant_tutors`
-- il 29/09 (migrazione 2026_09_29_tutor_assistenti.sql, pistacchio-lms.md
-- Sezione 8.0). Da allora la colonna resta sulle installazioni esistenti ma
-- non la legge piu' nessuno: qui si toglie.
--
-- Lo script e' scritto per essere innocuo dove non serve e per fermarsi dove
-- servirebbe ma non e' sicuro:
--
--   * su un'installazione nuova la colonna non c'e' e non succede niente;
--   * se la migrazione del 29/09 non e' stata eseguita, lo script si ferma
--     con un errore che ne dice il nome, invece di cancellare i legami;
--   * il nome della chiave esterna non e' noto (lo aveva assegnato MySQL), e
--     viene cercato invece che indovinato.
--
-- Rieseguirlo una seconda volta non fa nulla.

-- ---------------------------------------------------------------
-- 1. C'e' qualcosa da fare?
-- ---------------------------------------------------------------

SET @colonna := (
    SELECT COLUMN_NAME FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'supervising_tutor_id'
);

-- ---------------------------------------------------------------
-- 2. Salvaguardie: si toglie solo cio' che e' gia' stato trasferito
-- ---------------------------------------------------------------

-- La tabella di destinazione deve esistere. Se manca, la migrazione del 29/09
-- non e' stata eseguita e togliere la colonna perderebbe i legami: lo script
-- si ferma, e il nome della tabella inesistente nell'errore dice cosa fare.
SET @tabella := (
    SELECT TABLE_NAME FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'assistant_tutors'
);

SET @sql := IF(
    @colonna IS NOT NULL AND @tabella IS NULL,
    'SELECT 1 FROM eseguire_prima_la_migrazione_2026_09_29_tutor_assistenti',
    'DO 0'
);
PREPARE controllo FROM @sql; EXECUTE controllo; DEALLOCATE PREPARE controllo;

-- Ogni legame ancora scritto nella colonna dev'essere gia' presente nella
-- tabella. Se ne manca anche uno — per esempio un assistente assegnato dopo
-- il 29/09 su una copia non aggiornata — lo script si ferma.
SET @sql := IF(
    @colonna IS NULL,
    'DO 0',
    'SELECT COUNT(*) INTO @non_trasferiti
       FROM users u
       INNER JOIN users t
               ON t.id = u.supervising_tutor_id AND t.role = ''tutor''
       LEFT JOIN assistant_tutors a
              ON a.assistant_id = u.id AND a.tutor_id = u.supervising_tutor_id
      WHERE u.role = ''assistente''
        AND u.supervising_tutor_id IS NOT NULL
        AND a.assistant_id IS NULL'
);
SET @non_trasferiti := 0;
PREPARE controllo FROM @sql; EXECUTE controllo; DEALLOCATE PREPARE controllo;

SET @sql := IF(
    @non_trasferiti > 0,
    'SELECT 1 FROM ci_sono_legami_assistente_tutor_non_ancora_trasferiti',
    'DO 0'
);
PREPARE controllo FROM @sql; EXECUTE controllo; DEALLOCATE PREPARE controllo;

-- ---------------------------------------------------------------
-- 3. Chiave esterna, indice, colonna — in quest'ordine
-- ---------------------------------------------------------------

-- La chiave esterna va tolta per prima: finche' c'e', la colonna non si
-- cancella. Il nome lo aveva scelto MySQL, quindi si cerca.
SET @chiave := (
    SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'supervising_tutor_id'
      AND REFERENCED_TABLE_NAME IS NOT NULL
    LIMIT 1
);

SET @sql := IF(
    @chiave IS NULL,
    'DO 0',
    CONCAT('ALTER TABLE users DROP FOREIGN KEY `', @chiave, '`')
);
PREPARE passo FROM @sql; EXECUTE passo; DEALLOCATE PREPARE passo;

-- Togliere la chiave esterna non toglie l'indice che l'accompagnava.
SET @indice := (
    SELECT INDEX_NAME FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'supervising_tutor_id'
    LIMIT 1
);

SET @sql := IF(
    @indice IS NULL,
    'DO 0',
    CONCAT('ALTER TABLE users DROP INDEX `', @indice, '`')
);
PREPARE passo FROM @sql; EXECUTE passo; DEALLOCATE PREPARE passo;

SET @sql := IF(
    @colonna IS NULL,
    'DO 0',
    'ALTER TABLE users DROP COLUMN supervising_tutor_id'
);
PREPARE passo FROM @sql; EXECUTE passo; DEALLOCATE PREPARE passo;
