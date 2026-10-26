<?php

declare(strict_types=1);

namespace App\Auth;

use App\Core\Settings;

/**
 * Che cosa puo' ogni ruolo (26/10, Alessandro): un nucleo fisso, scritto
 * qui, e cinque interruttori che decide l'admin da Impostazioni › Ruoli.
 *
 * PERCHE'. Fino al 26/10 i permessi stavano nella tabella `role_permissions`
 * e la pagina «Permessi» diceva che si cambiavano da li', «senza toccare il
 * codice». Non era vero: circa meta' dei controlli guardava il ruolo e
 * ignorava le caselle, alcune caselle non le leggeva nessuno («consultare i
 * tentativi degli studenti seguiti») e altre aprivano accessi a meta' mai
 * provati. Un interruttore deve fare davvero quello che dice: per questo
 * sono pochi, ciascuno rispettato da tutte le pagine e le azioni che tocca, e
 * provato acceso e spento in `tests/permessi.js`.
 *
 * I ruoli sono quattro e restano quattro. I perimetri («i suoi gruppi», «gli
 * studenti che fa con lui», «i tutor che affianca») non sono permessi: li
 * calcolano `StudentScope`, `LiveScope`, `BachecaScope`, `GroupPeers`.
 *
 * La tabella `role_permissions` resta nel database ma non si legge piu'.
 */
final class Ruoli
{
    /**
     * Il nucleo fisso: i permessi che ogni ruolo ha sempre. Sono quelli che
     * il database di sviluppo aveva il 26/10, tolte le chiavi che nessuna
     * pagina controllava (`course.view`, `quiz.take`, `certificate.view_own`,
     * `quiz.grade`).
     */
    public const NUCLEO = [
        'admin' => [
            'board.manage', 'certificate.issue', 'course.create', 'course.delete', 'course.edit',
            'course.welcome', 'group.manage', 'question.answer', 'report.view', 'settings.manage',
            'user.manage',
        ],
        // `course.edit` del tutor non vuol dire piu' «modificare i corsi»
        // (dal 26/10 lo fa solo l'admin, `CourseRights::canEdit`): apre
        // «Gestione corsi» con i corsi dei suoi gruppi e gli incontri dei suoi
        // gruppi. `group.manage_own` cura i suoi gruppi, senza crearne,
        // eliminarne o assegnare corsi.
        'tutor' => ['board.manage_own', 'course.edit', 'course.welcome_own', 'group.manage_own', 'report.view'],
        'assistente' => [],
        'studente' => [],
    ];

    /**
     * Gli interruttori: chiave dell'impostazione => ruolo, permesso che
     * accende, e l'interruttore da cui dipende (se c'e'). Spenti solo se
     * l'admin li spegne: senza riga nelle impostazioni sono accesi, come
     * erano prima del 26/10.
     */
    public const INTERRUTTORI = [
        'ROLE_TUTOR_MEMBRI' => ['ruolo' => 'tutor', 'permesso' => 'group.members_own', 'richiede' => null],
        'ROLE_ASSISTENTE_REPORT' => ['ruolo' => 'assistente', 'permesso' => 'report.view_assigned', 'richiede' => null],
        'ROLE_ASSISTENTE_TENTATIVI' => ['ruolo' => 'assistente', 'permesso' => 'quiz.grade_assigned', 'richiede' => 'ROLE_ASSISTENTE_REPORT'],
        'ROLE_ASSISTENTE_INCONTRI' => ['ruolo' => 'assistente', 'permesso' => 'live.view_assigned', 'richiede' => null],
        'ROLE_STUDENTE_PROPOSTE' => ['ruolo' => 'studente', 'permesso' => 'board.propose', 'richiede' => null],
    ];

    /** Spento vale solo '0'; ogni altro valore, o nessuno, e' acceso. */
    public static function acceso(string $chiave): bool
    {
        $i = self::INTERRUTTORI[$chiave] ?? null;

        if ($i === null || Settings::get($chiave, '1') === '0') {
            return false;
        }

        return $i['richiede'] === null || self::acceso($i['richiede']);
    }

    /**
     * Tutti i permessi di un ruolo adesso: il nucleo piu' gli interruttori
     * accesi.
     *
     * @return list<string>
     */
    public static function permessi(string $ruolo): array
    {
        $permessi = self::NUCLEO[$ruolo] ?? [];

        foreach (self::INTERRUTTORI as $chiave => $i) {
            if ($i['ruolo'] === $ruolo && self::acceso($chiave)) {
                $permessi[] = $i['permesso'];
            }
        }

        return $permessi;
    }
}
