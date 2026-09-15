<?php

declare(strict_types=1);

/**
 * Mostra/nasconde i due set di opzioni in base al tipo di domanda.
 * Senza JavaScript il form resta utilizzabile per le domande a scelta singola
 * (il set "vero/falso" parte disabilitato, quindi non viene inviato).
 */
?>
<script>
    document.querySelectorAll('[data-question-form]').forEach(function (form) {
        var select = form.querySelector('[data-question-type]');

        if (!select) {
            return;
        }

        function sync() {
            form.querySelectorAll('[data-options]').forEach(function (set) {
                var active = set.getAttribute('data-options') === select.value;
                set.hidden = !active;
                set.disabled = !active;
            });
        }

        select.addEventListener('change', sync);
        sync();
    });
</script>
