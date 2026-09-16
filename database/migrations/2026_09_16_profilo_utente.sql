-- =====================================================
-- Migrazione incrementale — profilo dell'utente
-- Da eseguire solo su installazioni gia' esistenti.
-- =====================================================

-- Informazioni che l'utente compila da solo in /profilo. Tutte facoltative:
-- un profilo vuoto e' un profilo valido.
ALTER TABLE users
    ADD COLUMN bio         TEXT        NULL AFTER email_verified_at,
    ADD COLUMN phone       VARCHAR(40) NULL AFTER bio,
    ADD COLUMN city        VARCHAR(120) NULL AFTER phone,
    -- Percorso relativo a /storage, non un URL: l'immagine sta fuori dal
    -- document root e passa da un endpoint che richiede l'accesso.
    ADD COLUMN avatar_path VARCHAR(255) NULL AFTER city;
