-- Il passaggio della bozza dal supervisore all'Amministratore (14/11,
-- Alessandro). Una bozza e' sempre presso qualcuno: lo modifica solo lui,
-- l'altro la legge. Un solo passaggio, in una sola direzione: il supervisore
-- la invia all'Amministratore («Invia all'Amministratore»), oppure
-- l'Amministratore la riprende («Riprendi il corso»). Non si torna indietro.
--
--   presso      'supervisore' o 'amministratore'. Nasce secondo chi crea il
--               corso; i corsi che esistono gia' sono presso l'Amministratore
--               (in produzione non ci sono bozze del supervisore). Sui corsi
--               pubblicati non conta: li modifica solo l'Amministratore.
--   passato_il  quando la bozza e' passata all'Amministratore;
--   passaggio   come: 'invio' (dal supervisore) o 'ripresa' (dall'Amministratore).
--
-- Si puo' rieseguire: le colonne si aggiungono solo se mancano
-- (information_schema, perche' `ADD COLUMN IF NOT EXISTS` c'e' su MariaDB ma
-- non su MySQL 8).

SET @manca := (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'courses' AND COLUMN_NAME = 'presso');
SET @sql := IF(@manca,
    'ALTER TABLE courses ADD COLUMN presso ENUM(''supervisore'',''amministratore'') NOT NULL DEFAULT ''amministratore'' AFTER is_published',
    'DO 0');
PREPARE passo FROM @sql;
EXECUTE passo;
DEALLOCATE PREPARE passo;

SET @manca := (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'courses' AND COLUMN_NAME = 'passato_il');
SET @sql := IF(@manca,
    'ALTER TABLE courses ADD COLUMN passato_il DATETIME NULL DEFAULT NULL AFTER presso',
    'DO 0');
PREPARE passo FROM @sql;
EXECUTE passo;
DEALLOCATE PREPARE passo;

SET @manca := (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'courses' AND COLUMN_NAME = 'passaggio');
SET @sql := IF(@manca,
    'ALTER TABLE courses ADD COLUMN passaggio ENUM(''invio'',''ripresa'') NULL DEFAULT NULL AFTER passato_il',
    'DO 0');
PREPARE passo FROM @sql;
EXECUTE passo;
DEALLOCATE PREPARE passo;
