-- =====================================================
-- Migrazione incrementale — logo del gruppo
-- Da eseguire solo su installazioni gia' esistenti.
-- =====================================================

-- Percorso relativo a /storage del logo (PNG quadrato, lato max 256 px).
-- NULL: il gruppo mostra un riquadro con le proprie iniziali.
-- `groups` e' parola riservata su MySQL 8: backtick obbligatori.
ALTER TABLE `groups`
    ADD COLUMN logo_path VARCHAR(255) NULL AFTER description;
