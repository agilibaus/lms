<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\UserModel;

/**
 * Il filtro della pagina Utenti per gruppo e per tutor (20/11, Alessandro).
 *
 * FILTRA QUELLO CHE LA TABELLA MOSTRA. Le colonne Gruppi e Tutor hanno una
 * riga per gruppo, con il suo tutor accanto; chi non ha un valore ha «—», e
 * «—» e' una scelta del filtro come le altre. La colonna Tutor di un
 * assistente elenca i tutor che affianca, quella di uno studente senza
 * gruppi e del resto del personale ha «—». Un utente passa il criterio
 * Gruppo se una delle sue righe della colonna Gruppi e' quel gruppo, e il
 * criterio Tutor allo stesso modo; scelti tutti e due, deve passarli
 * entrambi (AND), su righe anche diverse.
 *
 * Il filtro viaggia nell'indirizzo (`?gruppo=…&tutor=…`): resta ordinando
 * una colonna, tornando indietro, e vale anche per «Scarica dati».
 */
final class FiltroUtenti
{
    public const NESSUNO = '—';

    /** @param array{gruppo: ?string, tutor: ?string} $scelta */
    private function __construct(private array $scelta)
    {
    }

    /** @param array<string, mixed> $parametri di solito `$_GET` */
    public static function daRichiesta(array $parametri): self
    {
        $valore = static function (mixed $v): ?string {
            return is_string($v) && trim($v) !== '' ? trim($v) : null;
        };

        return new self(['gruppo' => $valore($parametri['gruppo'] ?? null), 'tutor' => $valore($parametri['tutor'] ?? null)]);
    }

    public function gruppo(): ?string
    {
        return $this->scelta['gruppo'];
    }

    public function tutor(): ?string
    {
        return $this->scelta['tutor'];
    }

    /** Quanti criteri sono attivi: il numero di «Filtra (1)». */
    public function attivi(): int
    {
        return count(array_filter($this->scelta, static fn (?string $v): bool => $v !== null));
    }

    /** I parametri da rimettere negli indirizzi (scaricamento, ordinamento). */
    public function parametri(): array
    {
        return array_filter($this->scelta, static fn (?string $v): bool => $v !== null);
    }

    /**
     * Le due colonne di un utente come la tabella le scrive, una voce per
     * riga.
     *
     * @return array{gruppi: list<string>, tutor: list<string>}
     */
    public static function celle(array $utente): array
    {
        $coppie = UserModel::coppieGruppi($utente['gruppi_tutor'] ?? null);

        if ($coppie !== []) {
            return [
                'gruppi' => array_column($coppie, 'gruppo'),
                'tutor' => array_map(static fn (array $c): string => $c['tutor'] ?? self::NESSUNO, $coppie),
            ];
        }

        $riferimento = trim((string) ($utente['tutor_riferimento'] ?? ''));

        return [
            'gruppi' => [self::NESSUNO],
            // L'assistente: i tutor che affianca, separati da virgola come
            // nella cella (`UserModel::TUTOR_DI_RIFERIMENTO`).
            'tutor' => $riferimento === '' ? [self::NESSUNO] : explode(', ', $riferimento),
        ];
    }

    /**
     * @param list<array> $utenti
     * @return list<array>
     */
    public function applica(array $utenti): array
    {
        if ($this->attivi() === 0) {
            return $utenti;
        }

        return array_values(array_filter($utenti, function (array $u): bool {
            $celle = self::celle($u);

            return ($this->gruppo() === null || in_array($this->gruppo(), $celle['gruppi'], true))
                && ($this->tutor() === null || in_array($this->tutor(), $celle['tutor'], true));
        }));
    }

    /**
     * Le voci dei due menu: i valori che compaiono nella tabella, in ordine
     * alfabetico, con «—» in fondo se c'e'. Un valore scelto che non compare
     * piu' (un gruppo rinominato, un indirizzo vecchio) resta fra le voci,
     * cosi' il menu dice che cosa si sta filtrando.
     *
     * @param list<array> $utenti
     * @return array{gruppi: list<string>, tutor: list<string>}
     */
    public function voci(array $utenti): array
    {
        $gruppi = [];
        $tutor = [];

        foreach ($utenti as $u) {
            $celle = self::celle($u);
            foreach ($celle['gruppi'] as $g) {
                $gruppi[$g] = true;
            }
            foreach ($celle['tutor'] as $t) {
                $tutor[$t] = true;
            }
        }

        if ($this->gruppo() !== null) {
            $gruppi[$this->gruppo()] = true;
        }
        if ($this->tutor() !== null) {
            $tutor[$this->tutor()] = true;
        }

        return ['gruppi' => self::ordina(array_keys($gruppi)), 'tutor' => self::ordina(array_keys($tutor))];
    }

    /** @param list<int|string> $valori @return list<string> */
    private static function ordina(array $valori): array
    {
        $valori = array_map('strval', $valori);
        $nessuno = in_array(self::NESSUNO, $valori, true);
        $valori = array_values(array_filter($valori, static fn (string $v): bool => $v !== self::NESSUNO));
        sort($valori, SORT_NATURAL | SORT_FLAG_CASE);

        if ($nessuno) {
            $valori[] = self::NESSUNO;
        }

        return $valori;
    }
}
