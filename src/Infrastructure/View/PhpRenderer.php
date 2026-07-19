<?php

declare(strict_types=1);

namespace Academy\Infrastructure\View;

use RuntimeException;

final class PhpRenderer
{
    public function __construct(
        private readonly string $templatePath,
        private readonly Escaper $escaper,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function render(string $template, array $data = []): string
    {
        $file = $this->templatePath . '/' . $template . '.php';
        if (!is_readable($file)) {
            throw new RuntimeException(sprintf('Template "%s" was not found.', $template));
        }

        $e = $this->escaper;
        extract($data, EXTR_SKIP);

        ob_start();
        require $file;
        $content = ob_get_clean();

        if ($content === false) {
            throw new RuntimeException('Failed to render template.');
        }

        return $content;
    }
}
