-- =====================================================
-- Migrazione incrementale — cambio password dal profilo
-- Da eseguire solo su installazioni gia' esistenti.
-- =====================================================
--
-- Due colonne su `users`:
--
--   password_changed_at  quando la password e' stata cambiata l'ultima volta.
--                        La sessione ne tiene una copia presa all'accesso: se
--                        le due non combaciano piu', quella sessione e' stata
--                        aperta con la password vecchia e viene chiusa. E' il
--                        modo in cui cambiare la password fa cadere le altre
--                        sessioni dello stesso utente.
--
--   must_change_password  vale 1 quando la password l'ha generata un admin.
--                         Finche' resta 1, l'utente vede solo la pagina di
--                         cambio password.
--
-- Rieseguirla non fa nulla: le colonne si aggiungono solo se mancano.

SET @colonna := (
    SELECT COLUMN_NAME FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'password_changed_at'
);

SET @sql := IF(
    @colonna IS NULL,
    'ALTER TABLE users ADD COLUMN password_changed_at DATETIME NULL AFTER password_hash',
    'DO 0'
);
PREPARE passo FROM @sql; EXECUTE passo; DEALLOCATE PREPARE passo;

SET @colonna := (
    SELECT COLUMN_NAME FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'must_change_password'
);

SET @sql := IF(
    @colonna IS NULL,
    'ALTER TABLE users ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0 AFTER password_changed_at',
    'DO 0'
);
PREPARE passo FROM @sql; EXECUTE passo; DEALLOCATE PREPARE passo;

-- `password_changed_at` resta NULL per chi c'e' gia': non sappiamo quando ha
-- cambiato la password l'ultima volta, e inventare una data non servirebbe a
-- niente. NULL vuol dire "mai cambiata da quando esiste questa colonna", e il
-- confronto con la sessione funziona lo stesso. Chi e' collegato adesso non
-- viene buttato fuori dall'aggiornamento.
