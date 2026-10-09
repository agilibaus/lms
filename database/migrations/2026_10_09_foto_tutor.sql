-- =====================================================
-- Migrazione incrementale — una foto sola per il tutor (09/10)
-- =====================================================
--
-- Fino alla patch 0167 il benvenuto del tutor in cima al corso aveva una
-- foto sua, caricata apposta e una per corso, diversa da quella del profilo
-- che compare nella pagina del gruppo. Deciso da Alessandro: il tutor ha una
-- foto sola, quella del profilo, ripetuta ovunque, e ne ha sempre una.
--
-- Che cosa fa:
--
--   1. a ogni tutor che ha caricato la foto di un benvenuto, quella foto
--      diventa la foto del profilo: e' quella che gli studenti hanno visto in
--      cima al corso (decisione di Alessandro per il tutor del corso 1).
--      Con piu' benvenuti vale il piu' recente. La foto del profilo che il
--      tutor aveva prima resta sul disco e non e' piu' usata: la piattaforma
--      non cancella mai da sola un file caricato;
--   2. toglie la colonna `course_tutor_welcomes.photo_path`.
--
-- La foto presa dal benvenuto e' verticale e non quadrata: si vede lo stesso
-- bene, perche' ogni foto del profilo e' mostrata con `object-fit: cover`.
--
-- Su un'installazione nuova la colonna non c'e' e non succede niente.
-- Rieseguirla una seconda volta non fa nulla.

SET @colonna := (
    SELECT COLUMN_NAME FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'course_tutor_welcomes'
      AND COLUMN_NAME = 'photo_path'
);

SET @sql := IF(
    @colonna IS NULL,
    'DO 0',
    'UPDATE users u
        INNER JOIN (
            SELECT w.tutor_id, w.photo_path
            FROM course_tutor_welcomes w
            WHERE w.photo_path <> ''''
              AND NOT EXISTS (
                  SELECT 1 FROM course_tutor_welcomes piu_recente
                  WHERE piu_recente.tutor_id = w.tutor_id
                    AND piu_recente.photo_path <> ''''
                    AND (piu_recente.updated_at > w.updated_at
                         OR (piu_recente.updated_at = w.updated_at AND piu_recente.id > w.id))
              )
        ) ultima ON ultima.tutor_id = u.id
        SET u.avatar_path = ultima.photo_path'
);
PREPARE passo FROM @sql; EXECUTE passo; DEALLOCATE PREPARE passo;

SET @sql := IF(
    @colonna IS NULL,
    'DO 0',
    'ALTER TABLE course_tutor_welcomes DROP COLUMN photo_path'
);
PREPARE passo FROM @sql; EXECUTE passo; DEALLOCATE PREPARE passo;
