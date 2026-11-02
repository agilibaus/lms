-- Il ruolo «supervisore» (30/10, Alessandro): aiuta l'admin a costruire i
-- corsi. Lavora solo sui corsi in bozza (crea, modifica, elimina moduli,
-- lezioni, questionari); non pubblica, non elimina un corso, non vede i
-- corsi pubblicati, non gestisce utenti, gruppi, incontri, report. Delle
-- impostazioni apre solo «Video di benvenuto» e «Informazioni».
-- I permessi stanno in `Ruoli::NUCLEO`; qui serve solo il valore nuovo.
--
-- Si puo' rieseguire.

ALTER TABLE users
    MODIFY role ENUM('admin','supervisore','tutor','assistente','studente') NOT NULL DEFAULT 'studente';
