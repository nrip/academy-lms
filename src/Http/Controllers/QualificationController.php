<?php

declare(strict_types=1);

namespace Academy\Http\Controllers;

use Academy\Application\Identity\QualificationService;
use Academy\Domain\Exception\AuthenticationException;
use Academy\Domain\Exception\ConflictException;
use Academy\Domain\Exception\DomainRuleException;
use Academy\Domain\Exception\ValidationException;
use Academy\Domain\Security\AuthContext;
use Academy\Http\Middleware\AuthenticationMiddleware;
use Academy\Http\Middleware\SessionMiddleware;
use Academy\Infrastructure\View\PhpRenderer;
use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class QualificationController
{
    /** @var list<string> */
    private const FIELDS = [
        'qualification_type',
        'qualification_name',
        'institution_name',
        'university_or_board',
        'country',
        'completion_year',
        'registration_or_certificate_number',
    ];

    public function __construct(
        private readonly QualificationService $qualifications,
        private readonly PhpRenderer $renderer,
    ) {
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        return $this->renderList($request, [], [], null, $this->wasSaved($request), 200);
    }

    public function add(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();

        try {
            $this->qualifications->add($this->auth($request), $body);
        } catch (ValidationException $exception) {
            return $this->renderList(
                $request,
                $exception->fields(),
                $this->submittedValues($body),
                null,
                false,
                422,
            );
        } catch (DomainRuleException $exception) {
            return $this->renderList(
                $request,
                [],
                $this->submittedValues($body),
                $exception->getMessage(),
                false,
                422,
            );
        }

        return new RedirectResponse('/profile/qualifications?saved=1', 303);
    }

    /**
     * @param array<string, string> $args
     */
    public function update(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $id = (int) ($args['id'] ?? 0);
        $rowVersion = $this->intField($body, 'row_version');

        try {
            $this->qualifications->update($this->auth($request), $id, $rowVersion, $body);
        } catch (ValidationException $exception) {
            return $this->renderList($request, $exception->fields(), [], null, false, 422);
        } catch (ConflictException $exception) {
            return $this->renderList($request, [], [], $exception->getMessage(), false, 409);
        }

        return new RedirectResponse('/profile/qualifications?saved=1', 303);
    }

    /**
     * @param array<string, string> $args
     */
    public function delete(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $id = (int) ($args['id'] ?? 0);
        $rowVersion = $this->intField($body, 'row_version');

        try {
            $this->qualifications->delete($this->auth($request), $id, $rowVersion);
        } catch (ConflictException $exception) {
            return $this->renderList($request, [], [], $exception->getMessage(), false, 409);
        }

        return new RedirectResponse('/profile/qualifications?saved=1', 303);
    }

    /**
     * @param array<string, list<string>> $errors
     * @param array<string, string> $addValues
     */
    private function renderList(
        ServerRequestInterface $request,
        array $errors,
        array $addValues,
        ?string $conflict,
        bool $saved,
        int $status,
    ): ResponseInterface {
        $data = $this->qualifications->list($this->auth($request));

        $html = $this->renderer->render('pages/profile/qualifications', [
            'title' => 'Qualifications',
            'csrf' => $this->csrf($request),
            'qualifications' => $data['qualifications'],
            'maxQualifications' => QualificationService::MAX_QUALIFICATIONS,
            'errors' => $errors,
            'addValues' => $addValues,
            'conflict' => $conflict,
            'saved' => $saved,
        ]);

        return new HtmlResponse($html, $status);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, string>
     */
    private function submittedValues(array $body): array
    {
        $values = [];
        foreach (self::FIELDS as $field) {
            $raw = $body[$field] ?? '';
            $values[$field] = is_scalar($raw) ? (string) $raw : '';
        }

        return $values;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function intField(array $body, string $key): int
    {
        $value = $body[$key] ?? null;
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            return (int) $value;
        }

        return 0;
    }

    private function wasSaved(ServerRequestInterface $request): bool
    {
        $query = $request->getQueryParams();

        return isset($query['saved']) && (string) $query['saved'] === '1';
    }

    private function csrf(ServerRequestInterface $request): string
    {
        return (string) $request->getAttribute(SessionMiddleware::ATTR_RAW_CSRF, '');
    }

    private function auth(ServerRequestInterface $request): AuthContext
    {
        $auth = $request->getAttribute(AuthenticationMiddleware::ATTR_AUTH);
        if (!$auth instanceof AuthContext) {
            throw new AuthenticationException('Authentication required.');
        }

        return $auth;
    }
}
