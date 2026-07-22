<?php

declare(strict_types=1);

namespace Academy\Http\Controllers;

use Academy\Application\Identity\LearnerProfileService;
use Academy\Domain\Exception\AuthenticationException;
use Academy\Domain\Exception\ConflictException;
use Academy\Domain\Exception\ValidationException;
use Academy\Domain\Identity\LearnerProfile;
use Academy\Domain\Security\AuthContext;
use Academy\Http\Middleware\AuthenticationMiddleware;
use Academy\Http\Middleware\SessionMiddleware;
use Academy\Infrastructure\View\PhpRenderer;
use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class ProfileController
{
    /** @var list<string> */
    private const PERSONAL_FIELDS = [
        'first_name',
        'middle_name',
        'last_name',
        'preferred_display_name',
        'certificate_name',
        'date_of_birth',
        'gender',
        'nationality',
        'address_line_1',
        'address_line_2',
        'city',
        'state',
        'postal_code',
        'country',
        'alternate_mobile',
    ];

    /** @var list<string> */
    private const PROFESSIONAL_FIELDS = [
        'profession',
        'speciality',
        'current_designation',
        'organization_name',
        'years_of_experience',
        'medical_council_name',
        'medical_council_registration_number',
        'medical_council_registration_state',
        'registration_valid_from',
        'registration_valid_until',
    ];

    public function __construct(
        private readonly LearnerProfileService $profiles,
        private readonly PhpRenderer $renderer,
    ) {
    }

    public function overview(ServerRequestInterface $request): ResponseInterface
    {
        $overview = $this->profiles->overview($this->auth($request));

        $html = $this->renderer->render('pages/profile/overview', [
            'title' => 'My profile',
            'profile' => $overview['profile'],
            'completeness' => $overview['completeness'],
            'qualificationsCount' => $overview['qualifications_count'],
        ]);

        return new HtmlResponse($html);
    }

    public function showPersonal(ServerRequestInterface $request): ResponseInterface
    {
        $profile = $this->profiles->getPersonal($this->auth($request));

        return $this->renderPersonal(
            $request,
            $this->personalValues($profile),
            $profile->rowVersion,
            [],
            null,
            $this->wasSaved($request),
            200,
        );
    }

    public function updatePersonal(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $rowVersion = $this->intField($body, 'row_version');

        try {
            $this->profiles->updatePersonal($this->auth($request), $rowVersion, $body);
        } catch (ValidationException $exception) {
            return $this->renderPersonal(
                $request,
                $this->submittedValues($body, self::PERSONAL_FIELDS),
                $rowVersion,
                $exception->fields(),
                null,
                false,
                422,
            );
        } catch (ConflictException $exception) {
            $profile = $this->profiles->getPersonal($this->auth($request));

            return $this->renderPersonal(
                $request,
                $this->submittedValues($body, self::PERSONAL_FIELDS),
                $profile->rowVersion,
                [],
                $exception->getMessage(),
                false,
                409,
            );
        }

        return new RedirectResponse('/profile/personal?saved=1', 303);
    }

    public function showProfessional(ServerRequestInterface $request): ResponseInterface
    {
        $profile = $this->profiles->getProfessional($this->auth($request));

        return $this->renderProfessional(
            $request,
            $this->professionalValues($profile),
            $profile->rowVersion,
            [],
            null,
            $this->wasSaved($request),
            200,
        );
    }

    public function updateProfessional(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $rowVersion = $this->intField($body, 'row_version');

        try {
            $this->profiles->updateProfessional($this->auth($request), $rowVersion, $body);
        } catch (ValidationException $exception) {
            return $this->renderProfessional(
                $request,
                $this->submittedValues($body, self::PROFESSIONAL_FIELDS),
                $rowVersion,
                $exception->fields(),
                null,
                false,
                422,
            );
        } catch (ConflictException $exception) {
            $profile = $this->profiles->getProfessional($this->auth($request));

            return $this->renderProfessional(
                $request,
                $this->submittedValues($body, self::PROFESSIONAL_FIELDS),
                $profile->rowVersion,
                [],
                $exception->getMessage(),
                false,
                409,
            );
        }

        return new RedirectResponse('/profile/professional?saved=1', 303);
    }

    /**
     * @param array<string, string> $values
     * @param array<string, list<string>> $errors
     */
    private function renderPersonal(
        ServerRequestInterface $request,
        array $values,
        int $rowVersion,
        array $errors,
        ?string $conflict,
        bool $saved,
        int $status,
    ): ResponseInterface {
        $html = $this->renderer->render('pages/profile/personal', [
            'title' => 'Personal details',
            'csrf' => $this->csrf($request),
            'values' => $values,
            'rowVersion' => $rowVersion,
            'errors' => $errors,
            'conflict' => $conflict,
            'saved' => $saved,
        ]);

        return new HtmlResponse($html, $status);
    }

    /**
     * @param array<string, string> $values
     * @param array<string, list<string>> $errors
     */
    private function renderProfessional(
        ServerRequestInterface $request,
        array $values,
        int $rowVersion,
        array $errors,
        ?string $conflict,
        bool $saved,
        int $status,
    ): ResponseInterface {
        $html = $this->renderer->render('pages/profile/professional', [
            'title' => 'Professional details',
            'csrf' => $this->csrf($request),
            'values' => $values,
            'rowVersion' => $rowVersion,
            'errors' => $errors,
            'conflict' => $conflict,
            'saved' => $saved,
        ]);

        return new HtmlResponse($html, $status);
    }

    /**
     * @return array<string, string>
     */
    private function personalValues(LearnerProfile $profile): array
    {
        return [
            'first_name' => (string) $profile->firstName,
            'middle_name' => (string) $profile->middleName,
            'last_name' => (string) $profile->lastName,
            'preferred_display_name' => (string) $profile->preferredDisplayName,
            'certificate_name' => (string) $profile->certificateName,
            'certificate_name_confirmed' => $profile->certificateNameConfirmed ? '1' : '',
            'date_of_birth' => (string) $profile->dateOfBirth,
            'gender' => (string) $profile->gender,
            'nationality' => (string) $profile->nationality,
            'address_line_1' => (string) $profile->addressLine1,
            'address_line_2' => (string) $profile->addressLine2,
            'city' => (string) $profile->city,
            'state' => (string) $profile->state,
            'postal_code' => (string) $profile->postalCode,
            'country' => (string) $profile->country,
            'alternate_mobile' => (string) $profile->alternateMobile,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function professionalValues(LearnerProfile $profile): array
    {
        return [
            'profession' => (string) $profile->profession,
            'speciality' => (string) $profile->speciality,
            'current_designation' => (string) $profile->currentDesignation,
            'organization_name' => (string) $profile->organizationName,
            'years_of_experience' => $profile->yearsOfExperience === null ? '' : (string) $profile->yearsOfExperience,
            'medical_council_name' => (string) $profile->medicalCouncilName,
            'medical_council_registration_number' => (string) $profile->medicalCouncilRegistrationNumber,
            'medical_council_registration_state' => (string) $profile->medicalCouncilRegistrationState,
            'registration_valid_from' => (string) $profile->registrationValidFrom,
            'registration_valid_until' => (string) $profile->registrationValidUntil,
        ];
    }

    /**
     * @param array<string, mixed> $body
     * @param list<string> $fields
     * @return array<string, string>
     */
    private function submittedValues(array $body, array $fields): array
    {
        $values = [];
        foreach ($fields as $field) {
            $raw = $body[$field] ?? '';
            $values[$field] = is_scalar($raw) ? (string) $raw : '';
        }
        $confirmed = $body['certificate_name_confirmed'] ?? '';
        $values['certificate_name_confirmed'] = in_array(
            is_scalar($confirmed) ? (string) $confirmed : '',
            ['1', 'true', 'on', 'yes'],
            true,
        ) ? '1' : '';

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
