-- L'obiettivo del corso nella pagina dello studente (11/10, Alessandro):
-- completo nelle prime tre visite, poi ridotto a una riga, come il
-- benvenuto del tutor. Qui si contano le visite.
--
-- Una tabella sua e non quella del benvenuto (`course_welcome_views`):
-- quella conta solo le visite in cui c'e' un benvenuto, e le due cose
-- possono arrivare in momenti diversi. Le visite si contano solo quando il
-- corso ha un obiettivo.
--
-- Si puo' rieseguire: la tabella si crea solo se manca.

CREATE TABLE IF NOT EXISTS course_objective_views (
    user_id         INT UNSIGNED NOT NULL,
    course_id       INT UNSIGNED NOT NULL,
    visits          INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (user_id, course_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
