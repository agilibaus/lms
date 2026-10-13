-- I tentativi di accesso falliti (13/10, verifica di sicurezza): servono a
-- fermare chi prova password a ripetizione. Una riga per ogni password
-- sbagliata, con l'indirizzo scritto e l'IP da cui e' arrivata; le righe di
-- un indirizzo si cancellano quando quell'indirizzo entra, e quelle piu'
-- vecchie di un giorno a ogni nuovo errore. Le regole stanno in
-- `App\Core\TentativiAccesso`.
--
-- Si puo' rieseguire.

CREATE TABLE IF NOT EXISTS login_failures (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email           VARCHAR(255) NOT NULL,
    ip              VARCHAR(45) NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_email_created (email, created_at),
    KEY idx_ip_created (ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
