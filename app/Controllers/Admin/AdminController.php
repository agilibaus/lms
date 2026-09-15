<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

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
        http_response_code(404);
        echo $message;
    }

    protected function forbidden(string $message): void
    {
        http_response_code(403);
        echo $message;
    }
}
