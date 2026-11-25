-- Video di benvenuto: quando una persona l'ha visto.
--
-- PERCHE' UNA COLONNA E NON UNA TABELLA. E' un fatto solo per utente — l'ha
-- visto oppure no — e non ha una storia da conservare: una tabella a parte
-- sarebbe una riga per persona con dentro una data, cioe' una colonna
-- scritta in modo piu' complicato.
--
-- LA SECONDA ISTRUZIONE E' QUELLA CHE DECIDE IL COMPORTAMENTO, e va letta
-- prima di applicarla. La colonna nasce piena per **tutti gli utenti che
-- esistono gia'**: chi e' dentro da prima risulta aver gia' visto il
-- benvenuto e non viene interrotto da una schermata nuova. Lo vedranno solo
-- gli account creati da qui in avanti, che nascono con la colonna vuota.
-- E' la scelta di Elena del 05/10, ed e' irreversibile nel senso che conta:
-- una volta applicata, per far rivedere il benvenuto a qualcuno bisogna
-- svuotargli la casella a mano. Dalla pagina del profilo si puo' comunque
-- sempre rivedere il video: quello che non torna e' il rimando automatico.
--
-- La data serve a sapere **se**, non a fare statistiche: non si registra
-- quante volte lo si riguarda, perche' sarebbe un registro di abitudini di
-- una persona che nessuno ha chiesto (stessa regola del calendario, §8).
--
-- RIESEGUIRLA NON FA NIENTE (25/11). Prima la seconda istruzione si
-- fermava solo perche' `ADD COLUMN` falliva, con un errore, su una colonna
-- che c'era gia'; eseguita con `--force`, o in un giro di tutte le
-- migrazioni che va avanti dopo un errore, segnava come «gia' visto» chi
-- non l'aveva ancora visto, e quello studente il benvenuto non lo vedeva
-- piu'. Ora la colonna si aggiunge solo se manca, come nelle altre
-- migrazioni (MySQL 8 non ha `ADD COLUMN IF NOT EXISTS`), e gli utenti si
-- segnano **solo in quel momento**.

SET @colonna := (
    SELECT COLUMN_NAME FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'welcome_seen_at'
);

-- Gli utenti che c'erano prima: gia' visto, cosi' non vengono interrotti.
-- `NOW()` e non una data scritta a mano: l'ora la mette il database, che e'
-- l'orologio con cui tutto il resto confronta le date (trappola §5).
-- Le due istruzioni solo se la colonna non c'era: dopo, `DO 0`.
SET @sql := IF(
    @colonna IS NULL,
    'ALTER TABLE users ADD COLUMN welcome_seen_at DATETIME NULL DEFAULT NULL AFTER must_change_password',
    'DO 0'
);
PREPARE passo FROM @sql; EXECUTE passo; DEALLOCATE PREPARE passo;

SET @sql := IF(
    @colonna IS NULL,
    'UPDATE users SET welcome_seen_at = NOW() WHERE welcome_seen_at IS NULL',
    'DO 0'
);
PREPARE passo FROM @sql; EXECUTE passo; DEALLOCATE PREPARE passo;
