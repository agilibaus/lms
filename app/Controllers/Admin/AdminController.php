<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Errore;

/**
 * Helper comuni ai controller del pannello di amministrazione:
 * messaggi flash e redirect, per non ripeterli in ogni azione.
 */
abstract class AdminController
{
    protected function success(string $message, string $location): never
    {
        $_SESSION['flash_success'] = $message;

        $this->redirect($location);
    }

    protected function fail(string $message, string $location): never
    {
        $_SESSION['flash_error'] = $message;

        $this->redirect($location);
    }

    protected function redirect(string $location): never
    {
        header('Location: ' . $location);
        exit;
    }

    protected function notFound(string $message): void
    {
        Errore::pagina(404, $message);
    }

    protected function forbidden(string $message): void
    {
        Errore::pagina(403, $message);
    }
}
