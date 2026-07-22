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
 * Public catalogue (G-01-style listing) and course detail (G-02). No
 * authentication is required — see WP02_IMPLEMENTATION_NOTE.md "Public routes".
 */
final class CourseCatalogueController
{
    public function __construct(
        private readonly CatalogueService $catalogue,
        private readonly PhpRenderer $renderer,
    ) {
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $html = $this->renderer->render('pages/courses/index', [
            'title' => 'Courses',
            'courses' => $this->catalogue->listPublishedCourses(),
            'auth' => $this->optionalAuth($request),
        ]);

        return new HtmlResponse($html);
    }

    /**
     * @param array<string, string> $args
     */
    public function show(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $slug = (string) ($args['slug'] ?? '');
        $detail = $this->catalogue->getCourseDetail($slug);

        $html = $this->renderer->render('pages/courses/show', [
            'title' => $detail['version']->title,
            'course' => $detail['course'],
            'version' => $detail['version'],
            'eligibilityRules' => $detail['eligibilityRules'],
            'documentRequirements' => $detail['documentRequirements'],
            'batches' => $detail['batches'],
            'auth' => $this->optionalAuth($request),
            'csrf' => $this->csrf($request),
        ]);

        return new HtmlResponse($html);
    }

    /**
     * @param array<string, string> $args
     */
    public function batches(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $slug = (string) ($args['slug'] ?? '');
        $found = $this->catalogue->listBatchesForCourseSlug($slug);

        $html = $this->renderer->render('pages/courses/batches', [
            'title' => $found['version']->title . ' — Batches',
            'course' => $found['course'],
            'version' => $found['version'],
            'batches' => $found['batches'],
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
