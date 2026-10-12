-- «Iscrizione confermata» anche per le iscrizioni fatte dallo staff (12/10,
-- Alessandro): a mano dalla scheda del corso, entrando in un gruppo, o con
-- un corso assegnato a un gruppo intero.
--
-- Quelle email non partono nella pagina in cui si iscrive: un corso dato a
-- un gruppo di cento persone ne manderebbe cento in un colpo, oltre il
-- limite di invii del provider. Restano in coda in questa colonna e le
-- spedisce `bin/avvisi`, ogni quarto d'ora, venti per giro, come gli inviti.
--
-- 1 = l'email e' da mandare. Le iscrizioni che esistono gia' restano a 0:
-- nessuno riceve oggi la conferma di un'iscrizione di un mese fa.
--
-- Si puo' rieseguire: la colonna e l'indice si aggiungono solo se mancano.

SET @manca := (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'enrollments' AND COLUMN_NAME = 'confirmation_pending');
SET @sql := IF(@manca,
    'ALTER TABLE enrollments ADD COLUMN confirmation_pending TINYINT(1) NOT NULL DEFAULT 0 AFTER progress_pct',
    'DO 0');
PREPARE passo FROM @sql;
EXECUTE passo;
DEALLOCATE PREPARE passo;

SET @manca := (SELECT COUNT(*) = 0 FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'enrollments' AND INDEX_NAME = 'idx_confirmation_pending');
SET @sql := IF(@manca,
    'ALTER TABLE enrollments ADD INDEX idx_confirmation_pending (confirmation_pending)',
    'DO 0');
PREPARE passo FROM @sql;
EXECUTE passo;
DEALLOCATE PREPARE passo;
