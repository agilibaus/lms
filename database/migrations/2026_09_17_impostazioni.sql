-- =====================================================
-- Migrazione incrementale — impostazioni dal pannello
-- Da eseguire solo su installazioni gia' esistenti.
-- =====================================================

-- Configurazione di posta e Google Meet modificabile da /admin/settings.
-- Le chiavi hanno gli stessi nomi delle variabili del .env: se qui non c'e'
-- la riga, si legge il file come prima. Svuotare un valore dal pannello
-- cancella la riga e restituisce il comando al .env.
CREATE TABLE IF NOT EXISTS settings (
    setting_key     VARCHAR(100) NOT NULL PRIMARY KEY,
    setting_value   TEXT NULL,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by      INT UNSIGNED NULL,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Nuovo permesso, assegnato all'admin. Come gli altri si sposta poi dalla
-- matrice dei permessi, senza toccare il codice.
INSERT IGNORE INTO role_permissions (role, permission_key) VALUES ('admin','settings.manage');
