<?php

declare(strict_types=1);

namespace App\Support;

use Psr\Http\Message\ResponseInterface;

/**
 * Schlanker PHP-Template-Renderer mit Layout-Unterstützung.
 * Templates liegen unter /templates und sind reine .php-Dateien.
 */
final class View
{
    public function __construct(
        private readonly string $templateDir,
        /** @var array<string, mixed> global verfügbare Daten (z.B. aktueller User) */
        private array $shared = [],
    ) {
    }

    public function share(string $key, mixed $value): void
    {
        $this->shared[$key] = $value;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function render(
        ResponseInterface $response,
        string $template,
        array $data = [],
        ?string $layout = 'layout',
        int $status = 200,
    ): ResponseInterface {
        $content = $this->renderTemplate($template, $data);

        if ($layout !== null) {
            $content = $this->renderTemplate($layout, array_merge($data, ['content' => $content]));
        }

        $response->getBody()->write($content);

        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * @param array<string, mixed> $data
     */
    private function renderTemplate(string $template, array $data): string
    {
        $file = $this->templateDir . '/' . $template . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException(sprintf('Template "%s" nicht gefunden.', $template));
        }

        extract($this->shared, EXTR_SKIP);
        extract($data, EXTR_SKIP);

        ob_start();
        require $file;
        return (string) ob_get_clean();
    }
}
