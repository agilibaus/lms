-- Le date dei moduli per gruppo (26/10, Alessandro, conflitto 3): la data di
-- apertura sul modulo resta quella di tutto il corso; un gruppo, cioe' una
-- classe, puo' averne una sua per ciascun modulo dei suoi corsi. Per lo
-- studente vale la data del suo gruppo, se c'e' (fra piu' gruppi dello
-- stesso corso, la piu' vicina), altrimenti quella del corso
-- (`ModuleModel::dataPer`).
--
-- Nessuna riga = il gruppo segue il corso. Eliminando il gruppo o il modulo
-- si eliminano anche le sue date.
--
-- Si puo' rieseguire: la tabella si crea solo se manca.

CREATE TABLE IF NOT EXISTS group_module_dates (
    group_id        INT UNSIGNED NOT NULL,
    module_id       INT UNSIGNED NOT NULL,
    available_from  DATETIME NOT NULL,
    PRIMARY KEY (group_id, module_id),
    KEY idx_group_module_dates_module (module_id),
    CONSTRAINT fk_group_module_dates_group FOREIGN KEY (group_id) REFERENCES `groups`(id) ON DELETE CASCADE,
    CONSTRAINT fk_group_module_dates_module FOREIGN KEY (module_id) REFERENCES modules(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
