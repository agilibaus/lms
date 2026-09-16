-- =====================================================
-- Migrazione incrementale — ordinamento dei materiali della lezione
-- Da eseguire solo su installazioni gia' esistenti.
-- =====================================================

-- Ordine deciso da chi scrive la lezione: finora l'elenco seguiva la data di
-- caricamento, quindi dipendeva da quando si era caricato il file.
ALTER TABLE lesson_materials
    ADD COLUMN position SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER file_size_bytes;

-- Posizione iniziale = ordine di caricamento attuale, contato per lezione.
UPDATE lesson_materials m
JOIN (
    SELECT id,
           ROW_NUMBER() OVER (PARTITION BY lesson_id ORDER BY created_at, id) - 1 AS new_position
    FROM lesson_materials
) AS ordered ON ordered.id = m.id
SET m.position = ordered.new_position;

CREATE INDEX idx_lesson_materials_order ON lesson_materials (lesson_id, position);
