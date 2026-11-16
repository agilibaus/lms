-- ---------------------------------------------------
-- Via due tabelle che il codice non usa (16/11, Alessandro).
--
--   role_permissions  i permessi per ruolo. Dal 26/10 stanno nel codice
--                     (`app/Auth/Ruoli.php`): la tabella non si leggeva piu'.
--   remember_tokens   il «Ricordami» all'accesso, mai realizzato: vuota da
--                     sempre. Se un giorno si fara', si ricrea come servira'
--                     allora (e i suoi token vanno cancellati al cambio
--                     password, insieme alle sessioni).
--
-- Le migrazioni vecchie che scrivevano in `role_permissions` non vanno
-- rieseguite dopo questa: sono storia, e un'installazione nuova parte da
-- `schema.sql`, che le due tabelle non le ha piu'.
--
-- Si puo' rieseguire: IF EXISTS.
-- ---------------------------------------------------

DROP TABLE IF EXISTS role_permissions;
DROP TABLE IF EXISTS remember_tokens;
