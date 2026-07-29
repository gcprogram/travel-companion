<?php

declare(strict_types=1);

namespace App\Support;

use Psr\Http\Message\ResponseInterface;

/**
 * Lean PHP template renderer with layout support.
 * Templates live under /templates and are plain .php files.
 */
final class View
{
    public function __construct(
        private readonly string $templateDir,
        /** @var array<string, mixed> globally available data (e.g. the current user) */
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
            throw new \RuntimeException(sprintf('Template "%s" not found.', $template));
        }

        extract($this->shared, EXTR_SKIP);
        extract($data, EXTR_SKIP);

        ob_start();
        require $file;
        return (string) ob_get_clean();
    }
}
