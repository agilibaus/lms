-- =====================================================
-- Migrazione incrementale — origine della presenza alle sessioni live
-- Da eseguire solo su installazioni gia' esistenti.
-- =====================================================

-- 'platform': ingresso tracciato quando lo studente apre il Meet dalla piattaforma
-- 'manual'  : presenza segnata a mano dal tutor
ALTER TABLE live_session_attendance
    ADD COLUMN source ENUM('platform','manual') NOT NULL DEFAULT 'platform' AFTER joined_at;
