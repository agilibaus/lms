<?php

declare(strict_types=1);

use App\Core\CourseCover;
use App\Core\Csrf;

/**
 * Le risposte a una domanda del giro del cerchio (12/10): nella pagina del
 * gruppo e fra le domande passate. Ogni risposta con la foto (o le
 * iniziali) e il nome come li vede chi guarda, la propria con «(tu)». Chi
 * modera ha «Nascondi» o «Rimetti»; una risposta nascosta la vedono solo chi
 * l'ha scritta e chi modera, con una riga che lo dice.
 *
 * @var list<array<string, mixed>> $risposte
 * @var bool $modera
 */

if ($risposte === []) {
    return;
}
?>
<ul class="giro-risposte" role="list">
    <?php foreach ($risposte as $r): ?>
        <?php $rid = (int) $r['id']; $nascosta = $r['hidden_at'] !== null; ?>
        <li class="giro-risposta<?= $nascosta ? ' giro-risposta-nascosta' : '' ?>" id="risposta-<?= $rid ?>">
            <?php if (!empty($r['avatar_path'])): ?>
                <img class="avatar giro-foto" src="/utenti/<?= (int) $r['user_id'] ?>/immagine" alt="" loading="lazy">
            <?php else: ?>
                <span class="avatar avatar-placeholder giro-foto" aria-hidden="true"><?= htmlspecialchars(CourseCover::initials((string) $r['nome'])) ?></span>
            <?php endif; ?>
            <div class="giro-risposta-corpo">
                <div class="giro-risposta-testa">
                    <strong><?= htmlspecialchars((string) $r['nome']) ?><?= $r['mia'] ? ' (tu)' : '' ?></strong>
                    <?php if ($modera): ?>
                        <form action="/gruppi/risposte/<?= $rid ?><?= $ramo ?? '' ?>" method="post" class="giro-modera">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="azione" value="<?= $nascosta ? 'rimetti' : 'nascondi' ?>">
                            <button type="submit" class="link-btn"><?= $nascosta ? 'Rimetti' : 'Nascondi' ?></button>
                        </form>
                    <?php endif; ?>
                </div>
                <?php if ($nascosta): ?>
                    <p class="giro-nascosta-nota">
                        <?= $r['mia'] ? 'Nascosta dal tutor: la vedi solo tu.' : 'Nascosta: la vedono solo chi l’ha scritta e chi modera.' ?>
                    </p>
                <?php endif; ?>
                <p class="giro-risposta-testo"><?= nl2br(htmlspecialchars((string) $r['answer']), false) ?></p>
            </div>
        </li>
    <?php endforeach; ?>
</ul>
