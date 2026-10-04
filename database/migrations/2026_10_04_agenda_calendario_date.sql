-- Agenda: da quando esiste l'indirizzo del calendario, e quando e' stato
-- letto l'ultima volta.
--
-- PERCHE'. Il token e' una credenziale, e una credenziale di cui non si sa
-- niente non si sa nemmeno quando revocarla. Con queste due date la pagina
-- puo' dire «creato il 4 ottobre, letto l'ultima volta oggi alle 9:15»:
-- chi non lo usa piu' se ne accorge e lo disattiva, e chi vede una lettura
-- che non si spiega sa che e' il momento di rigenerarlo.
--
-- `used_at` si aggiorna a ogni lettura del calendario. E' una scrittura per
-- lettura, ed e' accettabile: un programma di calendario rilegge ogni
-- qualche ora, non ogni secondo, e la riga e' una sola.
--
-- Non registriamo **chi** ha letto — niente indirizzi IP, niente nomi di
-- programmi. La data dice quello che serve a decidere, il resto sarebbe un
-- registro di abitudini di una persona, tenuto per sempre, che nessuno ha
-- chiesto.
--
-- Chi aveva gia' creato il proprio indirizzo prima di questa migrazione
-- resta con le due date a NULL: la pagina lo dice («data non registrata»)
-- invece di inventare un giorno.

ALTER TABLE users
    ADD COLUMN calendar_token_created_at DATETIME NULL DEFAULT NULL AFTER calendar_token,
    ADD COLUMN calendar_token_used_at    DATETIME NULL DEFAULT NULL AFTER calendar_token_created_at;
