-- =====================================================
-- Migrazione incrementale — copertina del corso
-- Da eseguire solo su installazioni gia' esistenti.
-- =====================================================

-- La colonna `cover_image` esisteva gia': era pensata per un URL esterno da
-- stampare tal quale in <img src>, ma non c'era modo di valorizzarla
-- dall'interfaccia. Ora contiene il percorso relativo a /storage della misura
-- grande (es. "course-covers/12/a1b2c3....jpg"); accanto, sul disco, sta la
-- misura piccola con lo stesso nome piu' "-card". Un valore che comincia per
-- http:// o https:// continua a essere trattato come indirizzo esterno.
--
-- Manca solo il testo alternativo, che qui non aveva posto. NULL finche' non
-- viene scritto: in quel caso la vista ripiega sul titolo del corso.
ALTER TABLE courses
    ADD COLUMN cover_alt VARCHAR(255) NULL AFTER cover_image;
