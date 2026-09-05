<?php

declare(strict_types=1);

namespace Academy\Http\Controllers;

use Academy\Application\Courses\CloneCourseVersionService;
use Academy\Application\Courses\CourseAdminQueryService;
use Academy\Application\Courses\CreateBatchForPublishedVersionService;
use Academy\Application\Courses\PublishCourseVersionService;
use Academy\Domain\Courses\BatchRepository;
use Academy\Domain\Exception\AuthenticationException;
use Academy\Domain\Exception\AuthorizationException;
use Academy\Domain\Exception\ConflictException;
use Academy\Domain\Exception\DomainRuleException;
use Academy\Domain\Exception\NotFoundException;
use Academy\Domain\Exception\ValidationException;
use Academy\Domain\Security\AuthContext;
use Academy\Http\Middleware\AuthenticationMiddleware;
use Academy\Http\Middleware\SessionMiddleware;
use Academy\Infrastructure\View\PhpRenderer;
use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class CourseVersionLifecycleController
{
    public function __construct(
        private readonly CourseAdminQueryService $query,
        private readonly PublishCourseVersionService $publish,
        private readonly CloneCourseVersionService $clone,
        private readonly CreateBatchForPublishedVersionService $createBatch,
        private readonly BatchRepository $batches,
        private readonly PhpRenderer $renderer,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function publish(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $courseId = (int) ($args['courseId'] ?? 0);
        $versionId = (int) ($args['versionId'] ?? 0);

        try {
            $this->publish->publish($this->auth($request), $courseId, $versionId);
        } catch (ValidationException | ConflictException | DomainRuleException $exception) {
            return $this->versionError($request, $courseId, $versionId, $exception);
        }

        return new RedirectResponse(
            '/admin/courses/' . $courseId . '/versions/' . $versionId . '?published=1',
            303,
        );
    }

    /**
     * @param array<string, string> $args
     */
    public function cloneVersion(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $courseId = (int) ($args['courseId'] ?? 0);
        $versionId = (int) ($args['versionId'] ?? 0);

        try {
            $cloned = $this->clone->clone($this->auth($request), $courseId, $versionId);
        } catch (ValidationException | ConflictException | DomainRuleException | AuthorizationException $exception) {
            return $this->versionError($request, $courseId, $versionId, $exception);
        }

        return new RedirectResponse(
            '/admin/courses/' . $courseId . '/versions/' . $cloned->versionId . '?cloned=1',
            303,
        );
    }

    /**
     * @param array<string, string> $args
     */
    public function batchNewForm(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $courseId = (int) ($args['courseId'] ?? 0);
        $versionId = (int) ($args['versionId'] ?? 0);
        $detail = $this->query->courseDetail($this->auth($request), $courseId);
        $version = $this->findVersion($detail->versions, $versionId);

        $html = $this->renderer->render('pages/admin/courses/batch_new', [
            'title' => 'New batch',
            'csrf' => $this->csrf($request),
            'course' => $detail->course,
            'version' => $version,
            'values' => $this->defaultBatchValues(),
            'error' => null,
        ]);

        return new HtmlResponse($html);
    }

    /**
     * @param array<string, string> $args
     */
    public function batchCreate(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $courseId = (int) ($args['courseId'] ?? 0);
        $versionId = (int) ($args['versionId'] ?? 0);
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];

        try {
            $batch = $this->createBatch->create($this->auth($request), $courseId, $versionId, $body);
        } catch (ValidationException | ConflictException | DomainRuleException | AuthorizationException $exception) {
            $detail = $this->query->courseDetail($this->auth($request), $courseId);
            $version = $this->findVersion($detail->versions, $versionId);
            $html = $this->renderer->render('pages/admin/courses/batch_new', [
                'title' => 'New batch',
                'csrf' => $this->csrf($request),
                'course' => $detail->course,
                'version' => $version,
                'values' => [
                    'batch_code' => (string) ($body['batch_code'] ?? ''),
                    'name' => (string) ($body['name'] ?? ''),
                    'starts_at' => (string) ($body['starts_at'] ?? ''),
                    'ends_at' => (string) ($body['ends_at'] ?? ''),
                    'applications_open_at' => (string) ($body['applications_open_at'] ?? ''),
                    'applications_close_at' => (string) ($body['applications_close_at'] ?? ''),
                    'min_capacity' => (string) ($body['min_capacity'] ?? '1'),
                    'max_capacity' => (string) ($body['max_capacity'] ?? '30'),
                    'delivery_mode' => (string) ($body['delivery_mode'] ?? 'online'),
                    'venue_or_online_details' => (string) ($body['venue_or_online_details'] ?? ''),
                    'timezone' => (string) ($body['timezone'] ?? 'Asia/Kolkata'),
                    'currency' => (string) ($body['currency'] ?? 'INR'),
                    'fee_override' => (string) ($body['fee_override'] ?? ''),
                ],
                'error' => $exception->getMessage(),
            ]);

            return new HtmlResponse($html, $exception instanceof ConflictException ? 409 : 422);
        }

        return new RedirectResponse(
            '/admin/courses/' . $courseId . '/versions/' . $versionId . '?batch_created=' . $batch->batchId,
            303,
        );
    }

    /**
     * @param list<\Academy\Domain\Courses\CourseVersion> $versions
     */
    private function findVersion(array $versions, int $versionId): \Academy\Domain\Courses\CourseVersion
    {
        foreach ($versions as $candidate) {
            if ($candidate->versionId === $versionId) {
                return $candidate;
            }
        }

        throw new NotFoundException('Course version not found.');
    }

    /**
     * @return array<string, string>
     */
    private function defaultBatchValues(): array
    {
        $kolkata = new \DateTimeZone('Asia/Kolkata');
        $now = new \DateTimeImmutable('now', $kolkata);

        return [
            'batch_code' => '',
            'name' => '',
            'starts_at' => $now->modify('+30 days')->format('Y-m-d\T09:00'),
            'ends_at' => $now->modify('+90 days')->format('Y-m-d\T18:00'),
            'applications_open_at' => $now->modify('-1 day')->format('Y-m-d\T09:00'),
            'applications_close_at' => $now->modify('+20 days')->format('Y-m-d\T18:00'),
            'min_capacity' => '1',
            'max_capacity' => '30',
            'delivery_mode' => 'online',
            'venue_or_online_details' => 'Online sessions.',
            'timezone' => 'Asia/Kolkata',
            'currency' => 'INR',
            'fee_override' => '',
        ];
    }

    private function versionError(
        ServerRequestInterface $request,
        int $courseId,
        int $versionId,
        \Throwable $exception,
    ): ResponseInterface {
        $detail = $this->query->courseDetail($this->auth($request), $courseId);
        $version = $this->findVersion($detail->versions, $versionId);
        $html = $this->renderer->render('pages/admin/courses/version', [
            'title' => $version->title,
            'csrf' => $this->csrf($request),
            'course' => $detail->course,
            'version' => $version,
            'batches' => $this->batches->listByCourseVersionId($versionId),
            'error' => $exception->getMessage(),
            'flash' => null,
        ]);

        $status = 422;
        if ($exception instanceof ConflictException || $exception instanceof DomainRuleException) {
            $status = 409;
        }

        return new HtmlResponse($html, $status);
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
