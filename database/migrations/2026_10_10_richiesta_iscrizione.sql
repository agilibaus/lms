-- Il messaggio della richiesta di iscrizione (10/10, chiesto da Alessandro).
--
-- «Due righe su di te (facoltativo)», nel catalogo, sotto un corso con
-- l'iscrizione su richiesta: da 500 a 1.000 caratteri, con il contatore.
-- La colonna si allarga di conseguenza; i messaggi gia' scritti restano
-- come sono.
--
-- Si puo' rieseguire: allargare una colonna gia' larga cosi' non cambia
-- niente.

ALTER TABLE enrollment_requests MODIFY message VARCHAR(1000) NULL;
