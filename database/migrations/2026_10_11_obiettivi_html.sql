-- Obiettivo e descrizione con qualche tag HTML (11/10, chiesto da Alessandro).
--
-- L'obiettivo del corso e quello del modulo accettano ora pochi tag scritti a
-- mano (paragrafi, grassetto, corsivo, elenchi, collegamenti), e il limite di
-- 1.000 caratteri conta solo il testo visibile: con i tag, quello che si
-- salva puo' essere piu' lungo di 1.000, e la colonna VARCHAR(1000) della
-- migrazione `2026_10_11_obiettivi.sql` non basta piu'. Diventa TEXT, come
-- la descrizione del corso. Gli obiettivi gia' scritti restano come sono.
--
-- Si puo' rieseguire: una colonna gia' TEXT resta TEXT.

ALTER TABLE courses MODIFY objective TEXT NULL DEFAULT NULL;
ALTER TABLE modules MODIFY objective TEXT NULL DEFAULT NULL;
