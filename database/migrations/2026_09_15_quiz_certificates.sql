-- =====================================================
-- Migrazione incrementale — quiz obbligatori e certificati
-- Da eseguire solo su installazioni gia' esistenti
-- (schema.sql contiene gia' queste colonne per le nuove installazioni).
-- =====================================================

-- Gating: se 1, i moduli successivi restano bloccati finche' il quiz
-- di questo modulo non e' stato superato dallo studente.
ALTER TABLE modules
    ADD COLUMN quiz_required TINYINT(1) NOT NULL DEFAULT 0 AFTER position;

-- Tracciabilita' emissione e revoca dei certificati.
ALTER TABLE certificates
    ADD COLUMN issued_by      INT UNSIGNED NULL AFTER issued_at,
    ADD COLUMN revoked_at     DATETIME NULL AFTER issued_by,
    ADD COLUMN revoked_reason VARCHAR(255) NULL AFTER revoked_at,
    ADD CONSTRAINT fk_certificates_issued_by
        FOREIGN KEY (issued_by) REFERENCES users(id) ON DELETE SET NULL;
