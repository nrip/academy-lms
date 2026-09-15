<?php

declare(strict_types=1);

namespace Academy\Http\Controllers;

use Academy\Application\Learning\LearnerPlayerItemDetailView;
use Academy\Application\Learning\LearnerPlayerQueryService;
use Academy\Application\Learning\LearningMediaAccessService;
use Academy\Application\Learning\LearningQuestionQueryService;
use Academy\Application\Learning\MarkContentCompleteService;
use Academy\Domain\Exception\AuthenticationException;
use Academy\Domain\Exception\AuthorizationException;
use Academy\Domain\Exception\ConflictException;
use Academy\Domain\Exception\DomainRuleException;
use Academy\Domain\Exception\NotFoundException;
use Academy\Domain\Exception\ValidationException;
use Academy\Domain\Security\AuthContext;
use Academy\Http\Middleware\AuthenticationMiddleware;
use Academy\Http\Middleware\SessionMiddleware;
use Academy\Http\Security\SecurityHeaderPolicy;
use Academy\Infrastructure\View\PhpRenderer;
use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class LearnerPlayerController
{
    public function __construct(
        private readonly LearnerPlayerQueryService $query,
        private readonly MarkContentCompleteService $markComplete,
        private readonly LearningMediaAccessService $media,
        private readonly LearningQuestionQueryService $questions,
        private readonly PhpRenderer $renderer,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function outline(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $enrolmentId = (int) ($args['enrolmentId'] ?? 0);
        $outline = $this->query->outline($this->auth($request), $enrolmentId);
        $params = $request->getQueryParams();
        $flash = null;
        if (isset($params['completed'])) {
            $flash = 'Lesson marked complete.';
        }

        $html = $this->renderer->render('pages/learning/outline', [
            'title' => $outline->courseTitle . ' — Learning',
            'csrf' => $this->csrf($request),
            'outline' => $outline,
            'flash' => $flash,
            'error' => null,
        ]);

        return new HtmlResponse($html);
    }

    /**
     * @param array<string, string> $args
     */
    public function item(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $enrolmentId = (int) ($args['enrolmentId'] ?? 0);
        $contentId = (int) ($args['contentId'] ?? 0);

        try {
            $detail = $this->query->item($this->auth($request), $enrolmentId, $contentId);
        } catch (ConflictException $exception) {
            $html = $this->renderer->render('pages/learning/outline', [
                'title' => 'Learning',
                'csrf' => $this->csrf($request),
                'outline' => $this->query->outline($this->auth($request), $enrolmentId),
                'flash' => null,
                'error' => $exception->getMessage(),
            ]);

            return new HtmlResponse($html, 409);
        }

        $params = $request->getQueryParams();
        $flash = null;
        if (isset($params['asked'])) {
            $flash = 'Your question was sent.';
        } elseif (isset($params['closed'])) {
            $flash = 'Question closed.';
        }

        $threads = [];
        $canAsk = $this->questions->canAskOnLesson($detail->item->contentType);
        try {
            $threads = $this->questions->lessonThread($this->auth($request), $enrolmentId, $contentId);
        } catch (AuthorizationException) {
            $canAsk = false;
        }

        $html = $this->renderer->render('pages/learning/item', [
            'title' => $detail->item->title,
            'csrf' => $this->csrf($request),
            'detail' => $detail,
            'questions' => $threads,
            'canAsk' => $canAsk,
            'flash' => $flash,
            'error' => null,
        ]);

        return $this->withPodcastMediaHost(new HtmlResponse($html), $detail);
    }

    /**
     * @param array<string, string> $args
     */
    public function complete(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $enrolmentId = (int) ($args['enrolmentId'] ?? 0);
        $contentId = (int) ($args['contentId'] ?? 0);

        try {
            $this->markComplete->markComplete($this->auth($request), $enrolmentId, $contentId);
        } catch (ValidationException | DomainRuleException | ConflictException | AuthorizationException $exception) {
            try {
                $detail = $this->query->item($this->auth($request), $enrolmentId, $contentId);
                $html = $this->renderer->render('pages/learning/item', [
                    'title' => $detail->item->title,
                    'csrf' => $this->csrf($request),
                    'detail' => $detail,
                    'questions' => $this->safeLessonThread($request, $enrolmentId, $contentId),
                    'canAsk' => $this->questions->canAskOnLesson($detail->item->contentType),
                    'flash' => null,
                    'error' => $exception->getMessage(),
                ]);

                return $this->withPodcastMediaHost(
                    new HtmlResponse(
                        $html,
                        $exception instanceof ConflictException ? 409 : 422,
                    ),
                    $detail,
                );
            } catch (NotFoundException | ConflictException | AuthorizationException) {
                throw $exception;
            }
        }

        return new RedirectResponse(
            '/learning/enrolments/' . $enrolmentId . '?completed=1',
            303,
        );
    }

    /**
     * @param array<string, string> $args
     */
    public function media(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $enrolmentId = (int) ($args['enrolmentId'] ?? 0);
        $contentId = (int) ($args['contentId'] ?? 0);
        $issued = $this->media->issuePlaybackUrl($this->auth($request), $enrolmentId, $contentId);

        return new RedirectResponse($issued['download_url'], 302);
    }

    /**
     * Same-origin PDF bytes for PDF.js. Authorization matches the signed download URL.
     *
     * @param array<string, string> $args
     */
    public function mediaFile(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $enrolmentId = (int) ($args['enrolmentId'] ?? 0);
        $contentId = (int) ($args['contentId'] ?? 0);
        $file = $this->media->readPdfForViewer($this->auth($request), $enrolmentId, $contentId);
        $filename = str_replace(['"', '\\', "\r", "\n"], '', $file['filename']);
        $response = new \Laminas\Diactoros\Response('php://temp', 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $filename . '"',
            'Content-Length' => (string) strlen($file['bytes']),
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
        $response->getBody()->write($file['bytes']);

        return $response;
    }

    /**
     * @return list<\Academy\Application\Learning\LearningQuestionThreadItemView>
     */
    private function safeLessonThread(ServerRequestInterface $request, int $enrolmentId, int $contentId): array
    {
        try {
            return $this->questions->lessonThread($this->auth($request), $enrolmentId, $contentId);
        } catch (AuthorizationException) {
            return [];
        }
    }

    private function withPodcastMediaHost(HtmlResponse $response, LearnerPlayerItemDetailView $detail): HtmlResponse
    {
        if (!$detail->podcastDirect) {
            return $response;
        }
        $url = $detail->item->delivery->podcastUrl;
        $host = is_string($url) ? parse_url($url, PHP_URL_HOST) : null;
        if (!is_string($host) || $host === '') {
            return $response;
        }

        return $response->withHeader(SecurityHeaderPolicy::EXTRA_MEDIA_SRC_HEADER, strtolower($host));
    }

    private function auth(ServerRequestInterface $request): AuthContext
    {
        $auth = $request->getAttribute(AuthenticationMiddleware::ATTR_AUTH);
        if (!$auth instanceof AuthContext) {
            throw new AuthenticationException('Authentication required.');
        }

        return $auth;
    }

    private function csrf(ServerRequestInterface $request): string
    {
        return (string) $request->getAttribute(SessionMiddleware::ATTR_RAW_CSRF, '');
    }
}
