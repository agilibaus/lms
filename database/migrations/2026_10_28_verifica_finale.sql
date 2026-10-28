-- La verifica finale del corso (28/10, Alessandro): un modulo che l'admin
-- dichiara tale, con la casella «Verifica finale» in «Nuovo modulo» e
-- «Modifica modulo». Contiene solo il questionario, nessuna lezione; sta
-- sempre in fondo al corso; ce n'e' una sola per corso; il suo questionario
-- si apre quando lo studente ha completato tutti gli altri moduli.
--
-- 0 per tutti i moduli che esistono gia': nessun corso cambia comportamento.
-- Fino al 27/10 una verifica si riconosceva dalla forma (nessuna lezione e
-- il questionario); da oggi solo dalla casella, e un modulo con il solo
-- questionario e la casella spenta e' un modulo normale.
--
-- Si puo' rieseguire: la colonna si aggiunge solo se manca. Il controllo
-- passa da information_schema perche' `ADD COLUMN IF NOT EXISTS` c'e' su
-- MariaDB ma non su MySQL 8.

SET @manca := (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'modules' AND COLUMN_NAME = 'is_final');
SET @sql := IF(@manca,
    'ALTER TABLE modules ADD COLUMN is_final TINYINT(1) NOT NULL DEFAULT 0 AFTER quiz_required',
    'DO 0');
PREPARE passo FROM @sql;
EXECUTE passo;
DEALLOCATE PREPARE passo;
