<?php

declare(strict_types=1);

namespace App\Core;

class View
{
    /**
     * Renderizza una view. Con $withShell = true (default) il contenuto
     * viene incapsulato nel layout comune (sidebar + header).
     * Con $withShell = false la view è responsabile della pagina intera
     * (es. login, dove non serve la sidebar).
     */
    public static function render(string $view, array $data = [], bool $withShell = true): void
    {
        extract($data, EXTR_SKIP);
        $viewFile = __DIR__ . '/../Views/' . $view . '.php';

        if (!file_exists($viewFile)) {
            throw new \RuntimeException("View non trovata: {$view}");
        }

        if ($withShell) {
            ob_start();
            require $viewFile;
            $content = ob_get_clean();
            require __DIR__ . '/../Views/partials/shell.php';
        } else {
            require $viewFile;
        }
    }
}
