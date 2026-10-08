-- La pagina «Primo accesso» e la protezione predefinita del nome (08/10,
-- chiesto da Elena).
--
-- `name_display` diventa facoltativo: NULL vuol dire che lo studente non ha
-- ancora scelto come lo vedono gli altri studenti. Finche' non sceglie, gli
-- altri lo vedono con le sole iniziali (protezione predefinita, GDPR art.
-- 25): nessuno e' esposto con nome e cognome senza averlo deciso, nemmeno
-- chi non ha ancora fatto accesso. Al primo accesso la pagina «Primo
-- accesso» gli chiede di scegliere (e, se ha una password temporanea, di
-- cambiarla).
--
-- Gli studenti che ci sono gia' ripartono da «non ancora scelto»: per quasi
-- tutti «nome e cognome» era solo il valore di partenza, non una scelta, e
-- nei dati le due cose non si distinguono. In produzione non ce ne saranno
-- (Elena); nell'ambiente di sviluppo cosi' la pagina si vede e si prova.
--
-- Si puo' rieseguire: MODIFY e UPDATE portano allo stesso stato.

ALTER TABLE users
    MODIFY COLUMN name_display ENUM('full', 'first', 'initials') NULL DEFAULT NULL;

UPDATE users SET name_display = NULL WHERE role = 'studente';
