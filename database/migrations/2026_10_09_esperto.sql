-- L'esperto risponde (09/10, chiesto da Elena).
--
-- Alle domande degli studenti non risponde piu' il tutor del gruppo, ma
-- l'esperto, cioe' l'admin (`question.answer`). Il tutor legge l'archivio
-- come uno studente, nella pagina «L'esperto risponde».
--
-- Si toglie il permesso di rispondere alle domande dei propri gruppi, e le
-- domande ancora in attesa non sono piu' assegnate a un tutor: le vede, come
-- tutte, l'admin. Quelle gia' pubblicate o scartate tengono il loro tutor,
-- come storia.
--
-- Si puo' rieseguire: DELETE e UPDATE portano allo stesso stato.

DELETE FROM role_permissions WHERE permission_key = 'question.answer_own';

UPDATE course_questions SET tutor_id = NULL WHERE status = 'pending';
