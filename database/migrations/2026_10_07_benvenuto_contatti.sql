-- Il benvenuto del tutor: lo carica il tutor, e porta email e WhatsApp
-- (07/10, chiesto da Elena dopo la 0133).
--
-- 1. Il tutor carica il proprio benvenuto, nei corsi dei suoi gruppi. Come
--    per i gruppi (`group.manage` e `group.manage_own`), due permessi:
--    `course.welcome` all'admin per tutti i tutor, gia' esistente, e
--    `course.welcome_own` al tutor per il proprio.
-- 2. L'email che il tutor vuole dare ai suoi studenti: un campo a parte,
--    non l'email con cui accede. Vuoto: nel benvenuto non compare.
-- 3. Il link di invito al gruppo WhatsApp, uno per gruppo. Vuoto: nel
--    benvenuto non compare.
--
-- Le due colonne fanno fallire una seconda esecuzione (`ADD COLUMN` di una
-- colonna che c'e'), quindi la si applica una volta sola; l'INSERT IGNORE si
-- puo' ripetere.

INSERT IGNORE INTO role_permissions (role, permission_key) VALUES ('tutor', 'course.welcome_own');

ALTER TABLE users
    ADD COLUMN contact_email VARCHAR(255) NULL DEFAULT NULL AFTER email;

ALTER TABLE `groups`
    ADD COLUMN whatsapp_url VARCHAR(255) NULL DEFAULT NULL AFTER description;
