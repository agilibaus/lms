-- =====================================================
-- Migrazione incrementale — ordine dei corsi
-- Da eseguire solo su installazioni gia' esistenti.
-- =====================================================

-- Ordine deciso da chi amministra, usato nell'elenco dei corsi, in Gestione
-- corsi e nel catalogo degli studenti.
ALTER TABLE courses
    ADD COLUMN position SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER enrollment_mode;

-- Le righe esistenti partono nell'ordine in cui comparivano finora nel
-- pannello, cioe' dal piu' recente: senza questo passaggio si troverebbero
-- tutte a zero e l'ordine sembrerebbe casuale al primo caricamento.
SET @pos := -1;
UPDATE courses SET position = (@pos := @pos + 1) ORDER BY created_at DESC, id DESC;
