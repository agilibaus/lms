<?php

declare(strict_types=1);

namespace App\Core;

class Router
{
    /** @var array<int, array{method: string, regex: string, handler: array}> */
    private array $routes = [];

    public function get(string $pattern, array $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, array $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    private function add(string $method, string $pattern, array $handler): void
    {
        $this->routes[] = [
            'method' => $method,
            'regex' => $this->toRegex($pattern),
            'handler' => $handler,
        ];
    }

    private function toRegex(string $pattern): string
    {
        $regex = preg_replace('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', '(?P<$1>[^/]+)', $pattern);

        return '#^' . $regex . '$#';
    }

    public function dispatch(string $method, string $path): void
    {
        $path = rtrim($path, '/');
        $path = $path === '' ? '/' : $path;

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }

            if (preg_match($route['regex'], $path, $matches) === 1) {
                // Un corpo piu' grande di post_max_size viene scartato da PHP
                // prima che il codice parta: $_POST e $_FILES arrivano vuoti,
                // quindi manca anche il token e il controllo qui sotto
                // direbbe "sessione scaduta" a chi ha solo caricato un file
                // troppo grande. Va riconosciuto prima.
                if ($method === 'POST' && $_POST === [] && $_FILES === [] && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
                    http_response_code(413);
                    echo 'Il file inviato supera il limite del server, attualmente '
                        . htmlspecialchars(Upload::humanIniLimit())
                        . '. Scegli un file piu\' piccolo, oppure chiedi a chi amministra il server '
                        . 'di alzare upload_max_filesize e post_max_size nel php.ini.';

                    return;
                }

                if ($method === 'POST' && !Csrf::isValid($_POST[Csrf::FIELD] ?? null)) {
                    // Token assente o non valido: la richiesta non proviene da un
                    // form dell'applicazione (o la sessione e' scaduta).
                    http_response_code(419);
                    echo 'Sessione scaduta o richiesta non valida. Ricarica la pagina e riprova.';

                    return;
                }

                $params = array_filter(
                    $matches,
                    static fn ($key) => is_string($key),
                    ARRAY_FILTER_USE_KEY
                );

                [$controllerClass, $action] = $route['handler'];
                $controller = new $controllerClass();
                $controller->$action($params);

                return;
            }
        }

        http_response_code(404);
        echo '404 — Pagina non trovata.';
    }
}
