-- L'obiettivo del corso e l'obiettivo del modulo (11/10, chiesto da Alessandro).
--
-- Due campi facoltativi, scritti dal pannello: «Obiettivo del corso» in
-- «Nuovo corso» e «Modifica corso», subito prima della descrizione;
-- «Obiettivo del modulo» in «Nuovo modulo» e «Modifica modulo», subito prima
-- di «Disponibile dal». Al massimo 1.000 caratteri, con il contatore. Dove
-- compariranno agli studenti non e' ancora deciso: per ora si scrivono e
-- basta.
--
-- Vuoto e' NULL, come la descrizione: i corsi e i moduli che esistono gia'
-- restano senza obiettivo finche' qualcuno non lo scrive.
--
-- Si puo' rieseguire: ogni colonna si aggiunge solo se manca. Il controllo
-- passa da information_schema perche' `ADD COLUMN IF NOT EXISTS` c'e' su
-- MariaDB ma non su MySQL 8.

SET @manca := (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'courses' AND COLUMN_NAME = 'objective');
SET @sql := IF(@manca,
    'ALTER TABLE courses ADD COLUMN objective VARCHAR(1000) NULL DEFAULT NULL AFTER slug',
    'DO 0');
PREPARE passo FROM @sql;
EXECUTE passo;
DEALLOCATE PREPARE passo;

SET @manca := (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'modules' AND COLUMN_NAME = 'objective');
SET @sql := IF(@manca,
    'ALTER TABLE modules ADD COLUMN objective VARCHAR(1000) NULL DEFAULT NULL AFTER title',
    'DO 0');
PREPARE passo FROM @sql;
EXECUTE passo;
DEALLOCATE PREPARE passo;
