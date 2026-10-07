-- Nome e cognome separati, e la scelta di come comparire agli altri
-- studenti (07/10, chiesto da Elena).
--
-- Lo studente sceglie nel profilo se gli altri studenti lo vedono con nome
-- e cognome (il predefinito), con il solo nome o con le sole iniziali.
-- Tutor e admin vedono sempre nome e cognome. Da un nome completo unico non
-- si ricava con sicurezza il solo nome («Maria Grazia Rossi»), quindi nome e
-- cognome diventano due campi.
--
-- `full_name` NON SPARISCE: diventa una colonna calcolata dal database,
-- nome + cognome. Decine di pagine, report e ordinamenti la leggono e
-- continuano a leggerla; nessuno la scrive piu', e non puo' divergere dai
-- due campi. Una scrittura dimenticata su `full_name` da' errore subito,
-- invece di salvare un nome che poi nessuno vede.
--
-- LA DIVISIONE DEI NOMI CHE CI SONO GIA' e' automatica: la prima parola
-- e' il nome, il resto il cognome. Sbaglia con i nomi doppi («Maria Grazia
-- Rossi» diventa nome «Maria», cognome «Grazia Rossi»). L'ultima istruzione
-- elenca chi ha piu' di due parole, da controllare a mano dal pannello o
-- dal profilo.
--
-- Si applica una volta sola: le colonne esistono gia' al secondo giro e
-- `ADD COLUMN` fallisce prima di toccare qualunque dato.

ALTER TABLE users
    ADD COLUMN first_name VARCHAR(100) NOT NULL DEFAULT '' AFTER contact_email,
    ADD COLUMN last_name VARCHAR(100) NOT NULL DEFAULT '' AFTER first_name,
    ADD COLUMN name_display ENUM('full', 'first', 'initials') NOT NULL DEFAULT 'full' AFTER last_name;

UPDATE users
SET first_name = SUBSTRING_INDEX(TRIM(full_name), ' ', 1),
    last_name = TRIM(SUBSTRING(TRIM(full_name), CHAR_LENGTH(SUBSTRING_INDEX(TRIM(full_name), ' ', 1)) + 1));

ALTER TABLE users
    DROP COLUMN full_name,
    ADD COLUMN full_name VARCHAR(201) AS (TRIM(CONCAT(first_name, ' ', last_name))) STORED AFTER name_display;

-- Da controllare: nome o cognome con piu' di una parola.
SELECT id, email, first_name AS nome, last_name AS cognome
FROM users
WHERE first_name LIKE '% %' OR last_name LIKE '% %'
ORDER BY last_name, first_name;
