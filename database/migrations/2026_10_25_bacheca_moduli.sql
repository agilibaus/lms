-- La Bacheca per modulo (25/10, Alessandro): ogni risorsa appartiene a un
-- modulo del suo corso, obbligatorio, e lo studente la vede solo quando quel
-- modulo e' aperto per lui (data di apertura o questionario obbligatorio,
-- la stessa regola di lezioni, materiali e incontri). Lo studente sceglie il
-- modulo quando propone; il tutor lo puo' cambiare prima di pubblicare.
--
-- Le risorse che esistono gia' (in produzione nessuna pubblicata) prendono
-- il primo modulo del loro corso, cosi' restano visibili come prima.
--
-- Eliminando un modulo si eliminano anche le sue risorse della Bacheca, come
-- le sue lezioni; i file restano sul server.
--
-- Si puo' rieseguire: colonna, valori, vincolo e indice si aggiungono solo
-- se mancano.

SET @manca := (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'board_resources' AND COLUMN_NAME = 'module_id');
SET @sql := IF(@manca,
    'ALTER TABLE board_resources ADD COLUMN module_id INT UNSIGNED NULL AFTER category_id',
    'DO 0');
PREPARE passo FROM @sql;
EXECUTE passo;
DEALLOCATE PREPARE passo;

-- Il primo modulo del corso, per posizione e poi per id, come lo ordina la
-- pagina del corso. Una tabella derivata non correlata: valida su MySQL 8 e
-- su MariaDB (trappola gia' pagata con la 0168).
UPDATE board_resources r
INNER JOIN (
    SELECT m.course_id, MIN(m.id) AS module_id
    FROM modules m
    INNER JOIN (
        SELECT course_id, MIN(position) AS position FROM modules GROUP BY course_id
    ) primo ON primo.course_id = m.course_id AND primo.position = m.position
    GROUP BY m.course_id
) p ON p.course_id = r.course_id
SET r.module_id = p.module_id
WHERE r.module_id IS NULL;

-- Una risorsa rimasta senza modulo (il suo corso non ne ha) ferma il passo
-- qui sotto con «Invalid use of NULL value»: si aggiunge un modulo al corso
-- e si riesegue la migrazione.
SET @nullabile := (SELECT IS_NULLABLE = 'YES' FROM information_schema.COLUMNS
                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'board_resources' AND COLUMN_NAME = 'module_id');
SET @sql := IF(@nullabile,
    'ALTER TABLE board_resources MODIFY module_id INT UNSIGNED NOT NULL',
    'DO 0');
PREPARE passo FROM @sql;
EXECUTE passo;
DEALLOCATE PREPARE passo;

SET @manca := (SELECT COUNT(*) = 0 FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'board_resources' AND INDEX_NAME = 'idx_module_status');
SET @sql := IF(@manca,
    'ALTER TABLE board_resources ADD KEY idx_module_status (module_id, status)',
    'DO 0');
PREPARE passo FROM @sql;
EXECUTE passo;
DEALLOCATE PREPARE passo;

SET @manca := (SELECT COUNT(*) = 0 FROM information_schema.KEY_COLUMN_USAGE
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'board_resources'
                 AND COLUMN_NAME = 'module_id' AND REFERENCED_TABLE_NAME = 'modules');
SET @sql := IF(@manca,
    'ALTER TABLE board_resources ADD CONSTRAINT fk_board_resources_module FOREIGN KEY (module_id) REFERENCES modules(id) ON DELETE CASCADE',
    'DO 0');
PREPARE passo FROM @sql;
EXECUTE passo;
DEALLOCATE PREPARE passo;
