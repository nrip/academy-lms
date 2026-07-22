<?php

declare(strict_types=1);

namespace Academy\Http\Controllers;

use Academy\Application\Courses\CatalogueService;
use Academy\Domain\Security\AuthContext;
use Academy\Http\Middleware\AuthenticationMiddleware;
use Academy\Http\Middleware\SessionMiddleware;
use Academy\Infrastructure\View\PhpRenderer;
use Laminas\Diactoros\Response\HtmlResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Public batch summary (A-03). No authentication required.
 */
final class BatchController
{
    public function __construct(
        private readonly CatalogueService $catalogue,
        private readonly PhpRenderer $renderer,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function show(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $batchId = (int) ($args['batchId'] ?? 0);
        $found = $this->catalogue->getBatch($batchId);

        $html = $this->renderer->render('pages/batches/show', [
            'title' => $found['batch']->name,
            'course' => $found['course'],
            'version' => $found['version'],
            'batch' => $found['batch'],
            'availability' => $found['availability'],
            'auth' => $this->optionalAuth($request),
            'csrf' => $this->csrf($request),
        ]);

        return new HtmlResponse($html);
    }

    private function optionalAuth(ServerRequestInterface $request): ?AuthContext
    {
        $auth = $request->getAttribute(AuthenticationMiddleware::ATTR_AUTH);

        return $auth instanceof AuthContext ? $auth : null;
    }

    private function csrf(ServerRequestInterface $request): string
    {
        return (string) $request->getAttribute(SessionMiddleware::ATTR_RAW_CSRF, '');
    }
}
