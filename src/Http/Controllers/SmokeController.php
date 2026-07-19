<?php

declare(strict_types=1);

namespace Academy\Http\Controllers;

use Academy\Infrastructure\View\PhpRenderer;
use Laminas\Diactoros\Response\HtmlResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Neutral smoke page for Phase 0 design-system asset verification.
 */
final class SmokeController
{
    public function __construct(
        private readonly PhpRenderer $renderer,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $html = $this->renderer->render('pages/smoke', [
            'title' => 'Academy LMS — Smoke',
        ]);

        return new HtmlResponse($html);
    }
}
