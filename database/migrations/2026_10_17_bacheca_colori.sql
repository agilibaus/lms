-- La tavolozza 8x8 dei colori delle categorie della bacheca (17/10,
-- Alessandro, variante B). Il colore di una categoria non e' piu' il nome
-- di una delle quattro tavolozze del tema ma uno dei 64 colori della
-- tavolozza di `App\Core\Bacheca`, scritto #RRGGBB. Le quattro chiavi di
-- prima diventano il loro colore, che sta nella tavolozza: le categorie
-- gia' create restano come erano.
--
-- Si puo' rieseguire.

UPDATE board_categories SET color = '#4F7256' WHERE color = 'verde';
UPDATE board_categories SET color = '#3F6079' WHERE color = 'ardesia';
UPDATE board_categories SET color = '#9B4F2F' WHERE color = 'terracotta';
UPDATE board_categories SET color = '#6B4A77' WHERE color = 'prugna';

ALTER TABLE board_categories MODIFY color VARCHAR(20) NOT NULL DEFAULT '#4F7256';
