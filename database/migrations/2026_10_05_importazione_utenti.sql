-- Importazione di utenti da file: la coda degli inviti.
--
-- PERCHE' UNA CODA E NON UN INVIO SUBITO. Importando duecento persone,
-- duecento email identiche in trenta secondi sono due problemi insieme: il
-- limite di invii dell'hosting, e i filtri antispam che quel ritmo lo
-- riconoscono. Gli account si creano tutti subito; gli inviti escono a
-- scaglioni di venti, per mano del comando `bin/invita-utenti` pianificato
-- ogni quarto d'ora.
--
-- PERCHE' NON SI SALVA NESSUNA PASSWORD. La colonna dice solo che l'invito
-- deve ancora partire. La password viene generata **al momento dell'invio**,
-- se ne scrive l'impronta e il testo in chiaro vive il tempo di comporre
-- l'email: nessuna password in attesa dentro al database, che sarebbe la
-- cosa peggiore di tutta questa storia.
--
-- L'indice c'e' perche' la query della coda e' «chi ha ancora l'invito da
-- fare, i primi venti»: senza, e' una scansione dell'intera tabella utenti
-- ogni quarto d'ora.
--
-- Rieseguirla non fa danni: la colonna esiste gia' e `ADD COLUMN` fallisce.

ALTER TABLE users
    ADD COLUMN invite_pending TINYINT(1) NOT NULL DEFAULT 0 AFTER welcome_seen_at,
    ADD INDEX idx_invite_pending (invite_pending);
