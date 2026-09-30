<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\WatchIntervals;
use PDO;

/**
 * Accesso dati per `lesson_video_progress` (da dove riprende lo studente) e
 * `lesson_video_intervals` (quali parti ha guardato).
 *
 * Le due tabelle stanno in un modello solo perche' si scrivono sempre
 * insieme, nella stessa transazione: la posizione senza gli intervalli
 * darebbe una ripresa senza rendiconto, gli intervalli senza la posizione un
 * rendiconto senza ripresa.
 *
 * Gli orari li mette sempre il server, mai il browser. E' la ragione per cui
 * nessuna di queste funzioni accetta una data come parametro.
 */
class VideoProgressModel
{
    /**
     * Posizione e durata di uno studente su una lezione, o null se non ha
     * mai aperto quel video.
     *
     * @return array{position_seconds: int, duration_seconds: int|null, updated_at: string}|null
     */
    public static function position(int $userId, int $lessonId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT position_seconds, duration_seconds, updated_at
             FROM lesson_video_progress
             WHERE user_id = :user_id AND lesson_id = :lesson_id'
        );
        $stmt->execute(['user_id' => $userId, 'lesson_id' => $lessonId]);

        $riga = $stmt->fetch();

        if ($riga === false) {
            return null;
        }

        return [
            'position_seconds' => (int) $riga['position_seconds'],
            'duration_seconds' => $riga['duration_seconds'] === null ? null : (int) $riga['duration_seconds'],
            'updated_at' => (string) $riga['updated_at'],
        ];
    }

    /**
     * Quanti secondi di orologio sono passati dall'ultima scrittura di
     * questo studente su questa lezione, o null se non ce n'e' una.
     *
     * **Il conto lo fa il database, non PHP**, e non e' un vezzo: server e
     * MySQL possono stare su fusi diversi — nel container di sviluppo lo
     * sono davvero, MySQL su UTC e PHP su Europe/Rome. Confrontare `time()`
     * di PHP con un `DATETIME` scritto da `NOW()` sbaglia di due ore, e
     * sbaglia in silenzio: nel verso buono il controllo di plausibilita'
     * lascia passare qualunque cosa, nel verso cattivo butta via visioni
     * vere. Su un dato che finisce in un rendiconto e' il peggio che possa
     * capitare. Chiedendolo al database, i due istanti vengono dallo stesso
     * orologio. (Sezione 5 del promemoria.)
     */
    public static function secondsSinceLastWrite(int $userId, int $lessonId): ?int
    {
        $stmt = Database::connection()->prepare(
            'SELECT TIMESTAMPDIFF(SECOND, updated_at, NOW())
             FROM lesson_video_progress
             WHERE user_id = :user_id AND lesson_id = :lesson_id'
        );
        $stmt->execute(['user_id' => $userId, 'lesson_id' => $lessonId]);

        $secondi = $stmt->fetchColumn();

        if ($secondi === false || $secondi === null) {
            return null;
        }

        // Un valore negativo vorrebbe dire una riga scritta nel futuro: non
        // deve succedere, ma se succede vale zero, non un tetto enorme.
        return max(0, (int) $secondi);
    }

    /**
     * Gli intervalli gia' registrati, in ordine.
     *
     * @return list<array{0: int, 1: int}>
     */
    public static function intervals(int $userId, int $lessonId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT start_seconds, end_seconds
             FROM lesson_video_intervals
             WHERE user_id = :user_id AND lesson_id = :lesson_id
             ORDER BY start_seconds'
        );
        $stmt->execute(['user_id' => $userId, 'lesson_id' => $lessonId]);

        $intervalli = [];

        foreach ($stmt->fetchAll() as $riga) {
            $intervalli[] = [(int) $riga['start_seconds'], (int) $riga['end_seconds']];
        }

        return $intervalli;
    }

    /**
     * Registra una porzione di visione.
     *
     * Gli intervalli nuovi si uniscono a quelli gia' in tabella e la riga
     * viene riscritta: chi guarda una lezione intera lascia **una** riga, non
     * una al minuto. E' questo che tiene la tabella di una dimensione
     * ragionevole con cinquecento studenti.
     *
     * Tutto dentro una transazione: un errore a meta' non deve lasciare gli
     * intervalli cancellati e i nuovi non ancora scritti.
     *
     * @param list<array{0: int, 1: int}> $nuovi gia' passati da WatchIntervals::merge()
     */
    public static function record(
        int $userId,
        int $lessonId,
        int $position,
        ?int $duration,
        array $nuovi
    ): void {
        $pdo = Database::connection();
        $pdo->beginTransaction();

        try {
            // La posizione si sovrascrive sempre; `first_seen_at` no, perche'
            // e' la prima volta che quello studente ha aperto il video.
            $stmt = $pdo->prepare(
                'INSERT INTO lesson_video_progress
                     (user_id, lesson_id, position_seconds, duration_seconds, first_seen_at, updated_at)
                 VALUES (:user_id, :lesson_id, :position, :duration, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE
                     position_seconds = VALUES(position_seconds),
                     duration_seconds = COALESCE(VALUES(duration_seconds), duration_seconds),
                     updated_at = NOW()'
            );
            $stmt->execute([
                'user_id' => $userId,
                'lesson_id' => $lessonId,
                'position' => max(0, $position),
                'duration' => $duration !== null && $duration > 0 ? $duration : null,
            ]);

            if ($nuovi !== []) {
                $esistenti = self::intervalsForUpdate($pdo, $userId, $lessonId);
                $uniti = WatchIntervals::merge([...$esistenti, ...$nuovi], $duration);

                // Riscrivere solo se il risultato cambia: riguardare un pezzo
                // gia' visto non deve produrre scritture inutili.
                if ($uniti !== $esistenti) {
                    $cancella = $pdo->prepare(
                        'DELETE FROM lesson_video_intervals
                         WHERE user_id = :user_id AND lesson_id = :lesson_id'
                    );
                    $cancella->execute(['user_id' => $userId, 'lesson_id' => $lessonId]);

                    $inserisci = $pdo->prepare(
                        'INSERT INTO lesson_video_intervals
                             (user_id, lesson_id, start_seconds, end_seconds, recorded_at)
                         VALUES (:user_id, :lesson_id, :inizio, :fine, NOW())'
                    );

                    foreach ($uniti as [$inizio, $fine]) {
                        $inserisci->execute([
                            'user_id' => $userId,
                            'lesson_id' => $lessonId,
                            'inizio' => $inizio,
                            'fine' => $fine,
                        ]);
                    }
                }
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Come `intervals()`, ma dentro la transazione e con le righe bloccate:
     * due schede dello stesso studente sullo stesso video non devono
     * sovrascriversi a vicenda.
     *
     * @return list<array{0: int, 1: int}>
     */
    private static function intervalsForUpdate(PDO $pdo, int $userId, int $lessonId): array
    {
        $stmt = $pdo->prepare(
            'SELECT start_seconds, end_seconds
             FROM lesson_video_intervals
             WHERE user_id = :user_id AND lesson_id = :lesson_id
             ORDER BY start_seconds
             FOR UPDATE'
        );
        $stmt->execute(['user_id' => $userId, 'lesson_id' => $lessonId]);

        $intervalli = [];

        foreach ($stmt->fetchAll() as $riga) {
            $intervalli[] = [(int) $riga['start_seconds'], (int) $riga['end_seconds']];
        }

        return $intervalli;
    }

    /**
     * Il rendiconto di una lezione: una riga per ogni studente iscritto al
     * corso, anche chi il video non l'ha mai aperto — un rendiconto che
     * omette chi non ha guardato non e' un rendiconto.
     *
     * @return list<array{
     *     user_id: int, full_name: string, email: string,
     *     position_seconds: int|null, duration_seconds: int|null,
     *     watched_seconds: int, percentage: int|null,
     *     first_seen_at: string|null, updated_at: string|null,
     *     completed_at: string|null
     * }>
     */
    public static function reportForLesson(int $lessonId, int $courseId, ?int $lessonDuration): array
    {
        $pdo = Database::connection();

        $stmt = $pdo->prepare(
            'SELECT u.id AS user_id, u.full_name, u.email,
                    p.position_seconds, p.duration_seconds, p.first_seen_at, p.updated_at,
                    lp.completed_at
             FROM enrollments e
             INNER JOIN users u ON u.id = e.user_id
             LEFT JOIN lesson_video_progress p
                    ON p.user_id = u.id AND p.lesson_id = :lesson_id
             LEFT JOIN lesson_progress lp
                    ON lp.user_id = u.id AND lp.lesson_id = :lesson_id2
             WHERE e.course_id = :course_id
             ORDER BY u.full_name'
        );
        $stmt->execute([
            'lesson_id' => $lessonId,
            'lesson_id2' => $lessonId,
            'course_id' => $courseId,
        ]);

        $studenti = $stmt->fetchAll();

        if ($studenti === []) {
            return [];
        }

        // Gli intervalli di tutti gli studenti in una query sola: uno per
        // studente vorrebbe dire cinquecento query per aprire una pagina.
        $intervalli = self::intervalsForLesson($lessonId);

        $righe = [];

        foreach ($studenti as $studente) {
            $userId = (int) $studente['user_id'];
            $suoi = $intervalli[$userId] ?? [];

            // La durata del video di quello studente se il player l'ha
            // dichiarata, altrimenti quella scritta a mano sulla lezione.
            $durata = $studente['duration_seconds'] !== null
                ? (int) $studente['duration_seconds']
                : $lessonDuration;

            $righe[] = [
                'user_id' => $userId,
                'full_name' => (string) $studente['full_name'],
                'email' => (string) $studente['email'],
                'position_seconds' => $studente['position_seconds'] === null
                    ? null
                    : (int) $studente['position_seconds'],
                'duration_seconds' => $durata,
                'watched_seconds' => WatchIntervals::total($suoi),
                'percentage' => WatchIntervals::percentage($suoi, $durata),
                'first_seen_at' => $studente['first_seen_at'] === null
                    ? null
                    : (string) $studente['first_seen_at'],
                'updated_at' => $studente['updated_at'] === null
                    ? null
                    : (string) $studente['updated_at'],
                'completed_at' => $studente['completed_at'] === null
                    ? null
                    : (string) $studente['completed_at'],
            ];
        }

        return $righe;
    }

    /**
     * I corsi che hanno almeno una lezione con video, con quanti sono i
     * video e quanti studenti ne hanno aperto almeno uno. Serve all'elenco
     * dentro Report: un corso senza video non ha niente da mostrare li'.
     *
     * @return list<array{id: int, title: string, is_published: int, lezioni_video: int, iscritti: int, avviati: int}>
     */
    public static function coursesWithVideo(): array
    {
        $righe = Database::connection()->query(
            "SELECT c.id, c.title, c.is_published,
                    COUNT(DISTINCT l.id) AS lezioni_video,
                    (SELECT COUNT(*) FROM enrollments e WHERE e.course_id = c.id) AS iscritti,
                    COUNT(DISTINCT p.user_id) AS avviati
             FROM courses c
             INNER JOIN modules m ON m.course_id = c.id
             INNER JOIN lessons l ON l.module_id = m.id AND l.video_provider <> 'none'
             LEFT JOIN lesson_video_progress p ON p.lesson_id = l.id
             GROUP BY c.id, c.title, c.is_published
             ORDER BY c.title"
        )->fetchAll();

        $elenco = [];

        foreach ($righe as $riga) {
            $elenco[] = [
                'id' => (int) $riga['id'],
                'title' => (string) $riga['title'],
                'is_published' => (int) $riga['is_published'],
                'lezioni_video' => (int) $riga['lezioni_video'],
                'iscritti' => (int) $riga['iscritti'],
                'avviati' => (int) $riga['avviati'],
            ];
        }

        return $elenco;
    }

    /**
     * Le lezioni con video di un corso, in ordine di modulo e posizione.
     *
     * @return list<array{id: int, title: string, module_title: string, duration_seconds: int|null}>
     */
    public static function videoLessonsForCourse(int $courseId): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT l.id, l.title, l.duration_seconds, m.title AS module_title
             FROM lessons l
             INNER JOIN modules m ON m.id = l.module_id
             WHERE m.course_id = :course_id AND l.video_provider <> 'none'
             ORDER BY m.position, l.position, l.id"
        );
        $stmt->execute(['course_id' => $courseId]);

        $lezioni = [];

        foreach ($stmt->fetchAll() as $riga) {
            $durata = (int) ($riga['duration_seconds'] ?? 0);

            $lezioni[] = [
                'id' => (int) $riga['id'],
                'title' => (string) $riga['title'],
                'module_title' => (string) $riga['module_title'],
                'duration_seconds' => $durata > 0 ? $durata : null,
            ];
        }

        return $lezioni;
    }

    /**
     * Il rendiconto di un corso intero: una riga per studente iscritto, e
     * per ciascuno la percentuale vista di ogni lezione con video.
     *
     * E' la forma che serve a rendicontare — quanto ha seguito una persona,
     * lezione per lezione — mentre la pagina della singola lezione serve a
     * guardare una lezione alla volta. Stessi dati, due tagli.
     *
     * @param  list<array{id: int, title: string, module_title: string, duration_seconds: int|null}> $lezioni
     * @return list<array{
     *     user_id: int, full_name: string, email: string,
     *     per_lezione: array<int, array{percentuale: int|null, secondi: int}>,
     *     secondi_totali: int, percentuale_media: int|null, lezioni_avviate: int
     * }>
     */
    public static function courseMatrix(int $courseId, array $lezioni): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT u.id AS user_id, u.full_name, u.email
             FROM enrollments e
             INNER JOIN users u ON u.id = e.user_id
             WHERE e.course_id = :course_id
             ORDER BY u.full_name'
        );
        $stmt->execute(['course_id' => $courseId]);

        $studenti = $stmt->fetchAll();

        if ($studenti === [] || $lezioni === []) {
            return [];
        }

        // Intervalli e durate dichiarate di tutte le lezioni del corso in due
        // query, non due per studente: con cinquecento iscritti e venti
        // lezioni sarebbero ventimila query per aprire una pagina.
        $perLezione = [];
        $durateDichiarate = [];

        foreach ($lezioni as $lezione) {
            $perLezione[$lezione['id']] = self::intervalsForLesson($lezione['id']);
            $durateDichiarate[$lezione['id']] = self::declaredDurations($lezione['id']);
        }

        $righe = [];

        foreach ($studenti as $studente) {
            $userId = (int) $studente['user_id'];
            $celle = [];
            $totale = 0;
            $somma = 0;
            $conteggio = 0;
            $avviate = 0;

            foreach ($lezioni as $lezione) {
                $suoi = $perLezione[$lezione['id']][$userId] ?? [];
                $secondi = WatchIntervals::total($suoi);

                // La durata che il player ha dichiarato per quello studente
                // se c'e', altrimenti quella scritta sulla lezione.
                $durata = $durateDichiarate[$lezione['id']][$userId] ?? $lezione['duration_seconds'];
                $percentuale = WatchIntervals::percentage($suoi, $durata);

                $celle[$lezione['id']] = ['percentuale' => $percentuale, 'secondi' => $secondi];

                $totale += $secondi;

                if ($secondi > 0) {
                    $avviate++;
                }

                // La media e' su tutte le lezioni con video del corso, non
                // solo su quelle aperte: chi non ha mai aperto una lezione
                // ha guardato zero di quella lezione, non "niente da dire".
                if ($percentuale !== null) {
                    $somma += $percentuale;
                    $conteggio++;
                } elseif ($lezione['duration_seconds'] !== null) {
                    $conteggio++;
                }
            }

            $righe[] = [
                'user_id' => $userId,
                'full_name' => (string) $studente['full_name'],
                'email' => (string) $studente['email'],
                'per_lezione' => $celle,
                'secondi_totali' => $totale,
                'percentuale_media' => $conteggio > 0 ? (int) round($somma / $conteggio) : null,
                'lezioni_avviate' => $avviate,
            ];
        }

        return $righe;
    }

    /**
     * Le durate che il player ha dichiarato per una lezione, per studente.
     *
     * @return array<int, int>
     */
    public static function declaredDurations(int $lessonId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT user_id, duration_seconds
             FROM lesson_video_progress
             WHERE lesson_id = :lesson_id AND duration_seconds IS NOT NULL'
        );
        $stmt->execute(['lesson_id' => $lessonId]);

        $durate = [];

        foreach ($stmt->fetchAll() as $riga) {
            $durate[(int) $riga['user_id']] = (int) $riga['duration_seconds'];
        }

        return $durate;
    }

    /**
     * Tutti gli intervalli di una lezione, raccolti per studente.
     *
     * @return array<int, list<array{0: int, 1: int}>>
     */
    public static function intervalsForLesson(int $lessonId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT user_id, start_seconds, end_seconds
             FROM lesson_video_intervals
             WHERE lesson_id = :lesson_id
             ORDER BY user_id, start_seconds'
        );
        $stmt->execute(['lesson_id' => $lessonId]);

        $per = [];

        foreach ($stmt->fetchAll() as $riga) {
            $per[(int) $riga['user_id']][] = [(int) $riga['start_seconds'], (int) $riga['end_seconds']];
        }

        return $per;
    }
}
