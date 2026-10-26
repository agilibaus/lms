<?php

declare(strict_types=1);

use App\Core\Csrf;

/**
 * Impostazioni › Ruoli (26/10, Alessandro; mockup approvato). Per ogni ruolo
 * quello che fa sempre (il nucleo fisso di `App\Auth\Ruoli`) e i suoi
 * interruttori. Ogni frase deve essere vera: se cambia una regola, cambia
 * anche qui (le prove in `permessi.js` provano ogni interruttore acceso e
 * spento).
 *
 * @var array<string, bool> $stato la scelta salvata per ogni interruttore
 */

$ruoli = [
    'Admin' => [
        'sempre' => [
            'Tutto: corsi e contenuti, gruppi e loro corsi, utenti e iscrizioni, impostazioni.',
            'Risponde alle domande degli studenti come esperto.',
            'Vede report, tentativi e certificati di tutti.',
        ],
        'interruttori' => [],
    ],
    'Tutor' => [
        'sempre' => [
            'Legge tutti i corsi. In «Gestione corsi» trova i corsi dei suoi gruppi, con il proprio benvenuto da registrare e l’elenco dei suoi studenti.',
            'Cura i gruppi di cui è tutor: nome, immagine, descrizione, link WhatsApp, date dei moduli. Conduce il giro del cerchio. Non crea né elimina gruppi e non assegna corsi.',
            'Crea e gestisce gli incontri dei suoi gruppi, e ne segna le presenze.',
            'Pubblica nella Bacheca dei corsi dei suoi gruppi e modera proposte e racconti dei suoi studenti.',
            'Vede report, tentativi e certificati dei suoi studenti, nei corsi che fanno nei suoi gruppi; legge le loro Tre parole e «L’esperto risponde».',
        ],
        'interruttori' => [
            'ROLE_TUTOR_MEMBRI' => ['Membri dei gruppi',
                'Aggiunge al suo gruppo gli studenti già iscritti a un corso del gruppo che in quel corso non hanno ancora un gruppo, e ne toglie i membri. Spento: i membri li gestisce solo l’admin, e il tutor li vede.'],
        ],
    ],
    'Assistente' => [
        'sempre' => [
            'Affianca i tutor che l’admin gli assegna, in sola lettura.',
            'Legge tutti i corsi; legge la Bacheca e «L’esperto risponde» dei corsi dei suoi tutor, senza i nomi degli studenti.',
            'Non vede foto, pagine dei gruppi e Tre parole.',
        ],
        'interruttori' => [
            'ROLE_ASSISTENTE_REPORT' => ['Report',
                'Report di corso, studente e gruppo, Griglia, Studenti fermi, fruizione dei video, report degli incontri e certificati degli studenti dei suoi tutor. Spento: nessuno di questi, e il menu «Report» sparisce.'],
            'ROLE_ASSISTENTE_TENTATIVI' => ['Tentativi dei questionari',
                'Apre i tentativi degli studenti dei suoi tutor, con tutte le risposte, anche quelle aperte. Vale solo con «Report» acceso. Spento: nei report vede i punteggi, non il tentativo.'],
            'ROLE_ASSISTENTE_INCONTRI' => ['Incontri',
                'Vede in «Sessioni live», in Agenda e nel calendario gli incontri dei gruppi dei suoi tutor, ci entra senza risultare presente, e ne legge le presenze se «Report» è acceso. Spento: non li vede.'],
        ],
    ],
    'Studente' => [
        'sempre' => [
            'Segue i corsi a cui è iscritto: lezioni, questionari, incontri, i propri certificati.',
            'Fa domande all’esperto e legge le risposte; scrive le sue Tre parole.',
            'Partecipa alla pagina del suo gruppo: giro del cerchio, «Ci sarò». Legge e salva le risorse della Bacheca.',
        ],
        'interruttori' => [
            'ROLE_STUDENTE_PROPOSTE' => ['Proposte in Bacheca',
                'Propone risorse nella Bacheca dei suoi corsi e racconta come le ha usate. Spento: legge e salva, non propone né racconta; quello che aveva già proposto resta.'],
        ],
    ],
];
?>
<div class="page-header">
    <a href="/admin/settings" class="back-link"><span class="back-link-testo">Impostazioni</span></a>
    <h1>Ruoli</h1>
    <p class="page-subtitle">
        Che cosa fa ogni ruolo. Quello che è sotto «Sempre» è fisso; gli interruttori li decide l’admin, e valgono
        in tutte le pagine.
    </p>
</div>

<?php require __DIR__ . '/../_flash.php'; ?>

<form action="/admin/permissions" method="post" class="form ruoli">
    <?= Csrf::field() ?>

    <?php foreach ($ruoli as $nome => $ruolo): ?>
        <section class="card">
            <h2><?= htmlspecialchars($nome) ?></h2>
            <h3 class="ruoli-titoletto">Sempre</h3>
            <ul class="ruoli-sempre">
                <?php foreach ($ruolo['sempre'] as $frase): ?>
                    <li><?= htmlspecialchars($frase) ?></li>
                <?php endforeach; ?>
            </ul>

            <?php if ($ruolo['interruttori'] !== []): ?>
                <h3 class="ruoli-titoletto">Interruttori</h3>
                <?php foreach ($ruolo['interruttori'] as $chiave => [$etichetta, $descrizione]): ?>
                    <?php $id = strtolower(str_replace('_', '-', $chiave)); ?>
                    <?php /* Il valore nascosto arriva anche a casella spenta: cosi'
                             lo spento si salva come '0', non come «non inviato». */ ?>
                    <input type="hidden" name="ruoli[<?= $chiave ?>]" value="0">
                    <label class="checkbox-label ruoli-interruttore">
                        <input type="checkbox" name="ruoli[<?= $chiave ?>]" value="1" id="<?= $id ?>"
                               aria-describedby="<?= $id ?>-cosa" <?= ($stato[$chiave] ?? true) ? 'checked' : '' ?>>
                        <span>
                            <strong><?= htmlspecialchars($etichetta) ?></strong>
                            <span class="form-hint" id="<?= $id ?>-cosa"><?= htmlspecialchars($descrizione) ?></span>
                        </span>
                    </label>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Salva</button>
        <button type="reset" class="btn btn-secondary">Annulla</button>
    </div>
</form>
