-- I corsi a pagamento (06/11, Alessandro; disegno del 31/10): «A pagamento»
-- e' la quarta modalita' d'iscrizione, scelta corso per corso dall'admin.
-- Lo studente paga su Stripe, con il link di pagamento del corso; l'avviso
-- di Stripe che lo iscrive arriva con la patch successiva.
--
-- Tre colonne, vuote per tutti i corsi che esistono gia': nessun corso
-- cambia comportamento.
--   price_cents       il prezzo, in centesimi di euro, prima degli sconti;
--   payment_link_url  il link di pagamento di Stripe (https://buy.stripe.com/...);
--   payment_link_id   il suo identificativo (plink_...), che l'avviso di
--                     Stripe porta con se': da li' si riconosce il corso.
--
-- L'interruttore generale sta in `settings` (PAYMENTS_ENABLED) e nasce
-- spento: senza la riga, i corsi a pagamento non ci sono.
--
-- Si puo' rieseguire: MODIFY e' idempotente, e le colonne si aggiungono solo
-- se mancano (information_schema, perche' `ADD COLUMN IF NOT EXISTS` c'e' su
-- MariaDB ma non su MySQL 8).

ALTER TABLE courses
    MODIFY enrollment_mode ENUM('open','request','closed','paid') NOT NULL DEFAULT 'closed';

SET @manca := (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'courses' AND COLUMN_NAME = 'price_cents');
SET @sql := IF(@manca,
    'ALTER TABLE courses ADD COLUMN price_cents INT UNSIGNED NULL DEFAULT NULL AFTER enrollment_mode',
    'DO 0');
PREPARE passo FROM @sql;
EXECUTE passo;
DEALLOCATE PREPARE passo;

SET @manca := (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'courses' AND COLUMN_NAME = 'payment_link_url');
SET @sql := IF(@manca,
    'ALTER TABLE courses ADD COLUMN payment_link_url VARCHAR(255) NULL DEFAULT NULL AFTER price_cents',
    'DO 0');
PREPARE passo FROM @sql;
EXECUTE passo;
DEALLOCATE PREPARE passo;

SET @manca := (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'courses' AND COLUMN_NAME = 'payment_link_id');
SET @sql := IF(@manca,
    'ALTER TABLE courses ADD COLUMN payment_link_id VARCHAR(64) NULL DEFAULT NULL AFTER payment_link_url',
    'DO 0');
PREPARE passo FROM @sql;
EXECUTE passo;
DEALLOCATE PREPARE passo;
