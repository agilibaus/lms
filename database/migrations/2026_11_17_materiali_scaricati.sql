-- ---------------------------------------------------
-- Chi ha scaricato i materiali delle lezioni (17/11, Alessandro).
--
-- Una riga per studente e materiale, con la data del primo scaricamento:
-- i report del corso e dello studente dicono chi ha visto che cosa.
-- Solo gli studenti: lo staff che scarica per controllare non conta.
-- Si registra da quando c'e' questa tabella; prima non si sapeva.
--
-- Si puo' rieseguire: IF NOT EXISTS.
-- ---------------------------------------------------

CREATE TABLE IF NOT EXISTS material_downloads (
    material_id     INT UNSIGNED NOT NULL,
    user_id         INT UNSIGNED NOT NULL,
    first_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (material_id, user_id),
    FOREIGN KEY (material_id) REFERENCES lesson_materials(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_material_downloads_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
