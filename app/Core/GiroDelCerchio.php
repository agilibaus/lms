<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Chi fa che cosa nella pagina del gruppo (12/10, Alessandro), senza
 * database: la domanda al gruppo, le risposte, la moderazione, «Ci sarò».
 *
 *   - **pone la domanda** l'admin, per ogni gruppo, e il tutor del gruppo;
 *     sono gli stessi che **moderano**: nascondono una risposta e la
 *     rimettono;
 *   - **rispondono** i partecipanti del gruppo, una volta per domanda, e
 *     solo alla domanda aperta: l'archivio si legge e basta;
 *   - **una risposta nascosta** la vede solo chi l'ha scritta (con l'avviso
 *     che e' nascosta) e chi modera (per poterla rimettere); per gli altri
 *     non c'e';
 *   - **«Ci sarò»** lo dicono i partecipanti.
 *
 * Chi apre la pagina lo decide `GroupPeers::canViewGroup()`, come prima.
 */
final class GiroDelCerchio
{
    /** @param array{tutor_id?: int|string|null} $group */
    public static function modera(array $group, int $viewerId, bool $admin): bool
    {
        return $admin || ($viewerId > 0 && (int) ($group['tutor_id'] ?? 0) === $viewerId);
    }

    /** @param list<int> $memberIds */
    public static function partecipa(array $memberIds, int $viewerId): bool
    {
        return in_array($viewerId, $memberIds, true);
    }

    /**
     * Le risposte che chi guarda vede, con la sua per prima.
     *
     * @param list<array<string, mixed>> $answers
     * @return list<array<string, mixed>>
     */
    public static function visibili(array $answers, int $viewerId, bool $modera): array
    {
        $mie = [];
        $altre = [];

        foreach ($answers as $risposta) {
            $sua = (int) $risposta['user_id'] === $viewerId;

            if ($risposta['hidden_at'] !== null && !$sua && !$modera) {
                continue;
            }

            if ($sua) {
                $mie[] = $risposta;
            } else {
                $altre[] = $risposta;
            }
        }

        return array_merge($mie, $altre);
    }

    /** Quante risposte contano nel «3 risposte su 6»: quelle non nascoste. */
    public static function contate(array $answers): int
    {
        return count(array_filter($answers, static fn (array $r): bool => $r['hidden_at'] === null));
    }

    /** «1 risposta su 6», «3 risposte su 6», «Nessuna risposta ancora». */
    public static function conto(int $risposte, int $partecipanti): string
    {
        if ($risposte === 0) {
            return 'Nessuna risposta ancora';
        }

        return $risposte . ($risposte === 1 ? ' risposta' : ' risposte') . ' su ' . $partecipanti;
    }

    /** «venerdì 16 ottobre · 14:00–15:00», per il prossimo incontro. */
    public static function quando(string $inizio, string $fine): string
    {
        $da = new \DateTimeImmutable($inizio);
        $a = new \DateTimeImmutable($fine);
        $mesi = [1 => 'gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno',
            'luglio', 'agosto', 'settembre', 'ottobre', 'novembre', 'dicembre'];

        return Agenda::nomiGiorni()[(int) $da->format('N') - 1] . ' ' . $da->format('j') . ' '
            . $mesi[(int) $da->format('n')] . ' · ' . $da->format('H:i') . '–' . $a->format('H:i');
    }

    /** «Stefania chiede al gruppo», o senza nome se l'autore non c'e' piu'. */
    public static function chiChiede(?string $nome): string
    {
        $nome = trim((string) $nome);

        return $nome !== '' ? $nome . ' chiede al gruppo' : 'Domanda al gruppo';
    }
}
