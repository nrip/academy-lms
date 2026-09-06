<?php

declare(strict_types=1);

namespace Academy\Infrastructure\View;

use Academy\Application\Branding\AcademyBranding;
use Academy\Application\Identity\NavigationMenuBuilder;
use Academy\Http\View\CurrentAuth;
use Academy\Http\View\CurrentCsrfToken;
use RuntimeException;

final class PhpRenderer
{
    private readonly AcademyBranding $branding;

    public function __construct(
        private readonly string $templatePath,
        private readonly Escaper $escaper,
        private readonly ?CurrentAuth $currentAuth = null,
        private readonly ?NavigationMenuBuilder $navigation = null,
        private readonly ?CurrentCsrfToken $currentCsrf = null,
        ?AcademyBranding $branding = null,
    ) {
        $this->branding = $branding ?? AcademyBranding::defaults();
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

        if (!isset($data['navItems']) && $this->navigation !== null) {
            $auth = $this->currentAuth?->get();
            $data['navItems'] = $this->navigation->build($auth);
            // Shell POST controls (logout) must work on pages that render no form
            // of their own, so fall back to the request-scoped session token.
            $pageCsrf = isset($data['csrf']) && is_string($data['csrf']) ? $data['csrf'] : '';
            $data['navCsrf'] = $pageCsrf !== '' ? $pageCsrf : ($this->currentCsrf?->get() ?? '');
        }

        if (!isset($data['branding']) || !$data['branding'] instanceof AcademyBranding) {
            $data['branding'] = $this->branding;
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
