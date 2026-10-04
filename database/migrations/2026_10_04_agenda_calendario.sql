-- Agenda dello studente: l'indirizzo personale del calendario.
--
-- COS'E'. Un indirizzo `.ics` che si incolla una volta in Google Calendar o
-- in Calendario di Apple e che poi resta aggiornato da se': gli incontri
-- nuovi compaiono li' senza che lo studente rifaccia niente.
--
-- PERCHE' UNA COLONNA E NON UN CALCOLO. Il token si potrebbe ricavare dai
-- dati dell'utente con una firma, senza toccare il database. Ma allora non
-- sarebbe revocabile: chi ha avuto il link una volta ce l'ha per sempre, e
-- l'unico modo di togliergli l'accesso sarebbe cambiare la chiave
-- dell'applicazione — cioe' spegnere il calendario a tutti. Una colonna si
-- rigenera per un utente solo.
--
-- E' UNA CREDENZIALE, non un identificativo: chi ha il link vede gli
-- impegni di quella persona, senza fare l'accesso — e' il modo in cui
-- funzionano i calendari sottoscritti, compresi quelli di Google. Da qui le
-- tre scelte:
--
--   * NULL finche' lo studente non lo chiede: un segreto che non e' mai
--     stato creato non puo' essere rubato, e la maggior parte delle persone
--     non usera' questa funzione;
--   * UNIQUE, cosi' una collisione diventa un errore del database invece di
--     due persone che vedono lo stesso calendario;
--   * 64 caratteri per stare larghi: oggi ne sono 48 (24 byte casuali in
--     esadecimale), e cambiare lunghezza un domani non richiedera' una
--     migrazione.
--
-- La migrazione si puo' rieseguire: se la colonna c'e' gia', MariaDB da'
-- errore 1060 e non fa danni.

ALTER TABLE users
    ADD COLUMN calendar_token VARCHAR(64) NULL DEFAULT NULL AFTER avatar_path,
    ADD UNIQUE KEY uq_users_calendar_token (calendar_token);
