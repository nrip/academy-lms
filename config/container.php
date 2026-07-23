<?php

declare(strict_types=1);

use Academy\Application\Admissions\ApplicationDeclarationService;
use Academy\Application\Admissions\ApplicationSubmitService;
use Academy\Application\Admissions\ApplicationWorkspaceService;
use Academy\Application\Admissions\DraftApplicationService;
use Academy\Application\Audit\AuditRedactor;
use Academy\Application\Audit\AuditService;
use Academy\Application\Courses\CatalogueService;
use Academy\Application\Credentials\DocumentDownloadService;
use Academy\Application\Credentials\DocumentScanWorker;
use Academy\Application\Credentials\DocumentUploadService;
use Academy\Application\Credentials\StuckScanWatchService;
use Academy\Application\Dashboard\LearnerDashboardQueryService;
use Academy\Application\Dashboard\LearnerStatusPresenter;
use Academy\Application\Identity\CompositeTokenConsumedHandler;
use Academy\Application\Identity\EmailVerificationResendService;
use Academy\Application\Identity\EmailVerificationTokenConsumedHandler;
use Academy\Application\Identity\ForgotPasswordService;
use Academy\Application\Identity\InitialApplicantRoleBinder;
use Academy\Application\Identity\LearnerProfileService;
use Academy\Application\Identity\LoginService;
use Academy\Application\Identity\LogoutService;
use Academy\Application\Identity\MobileOtpResendService;
use Academy\Application\Identity\MobileOtpVerificationService;
use Academy\Application\Identity\PasswordHasher;
use Academy\Application\Identity\PasswordResetService;
use Academy\Application\Identity\PostLoginDestinationResolver;
use Academy\Application\Identity\QualificationService;
use Academy\Application\Identity\RegistrationService;
use Academy\Application\Identity\TokenConfirmationCleanupService;
use Academy\Application\Identity\TokenConfirmationService;
use Academy\Application\Identity\VerificationChallengeIssuer;
use Academy\Application\Identity\VerificationTokenIssuer;
use Academy\Application\Notifications\AdminNotificationQueryService;
use Academy\Application\Notifications\AdminNotificationRetryService;
use Academy\Application\Notifications\DeliveryFinaliser;
use Academy\Application\Notifications\IdentityNotificationDeliveryWorker;
use Academy\Application\Notifications\NotificationCapability;
use Academy\Application\Notifications\NotificationContextResolver;
use Academy\Application\Notifications\NotificationRecipientResolver;
use Academy\Application\Notifications\NotificationTemplateRenderer;
use Academy\Application\Notifications\TransactionalNotificationDeliveryWorker;
use Academy\Application\Notifications\TransactionalNotificationTemplateRegistry;
use Academy\Application\Outbox\OutboxRelayService;
use Academy\Application\Payments\FinancePaymentQueryService;
use Academy\Application\Payments\FinanceReconciliationQueryService;
use Academy\Application\Payments\PaymentCheckoutService;
use Academy\Application\Payments\PaymentReconciliationService;
use Academy\Application\Payments\PaymentWebhookProcessor;
use Academy\Application\Payments\RazorpayWebhookIngressService;
use Academy\Application\Payments\SuccessfulPaymentAcceptanceService;
use Academy\Application\RBAC\AuthorizationService;
use Academy\Application\RBAC\RoleAssignmentService;
use Academy\Application\Review\ApplicationCorrectionRequestService;
use Academy\Application\Review\ApplicationDecisionService;
use Academy\Application\Review\DocumentReviewService;
use Academy\Application\Review\LearnerCorrectionResubmitService;
use Academy\Application\Review\ReviewerAccessGuard;
use Academy\Application\Review\ReviewerApplicationQueryService;
use Academy\Application\Review\ReviewerClaimService;
use Academy\Application\Security\CsrfTokenManager;
use Academy\Application\Security\RateLimiter;
use Academy\Application\Security\RateLimitKeyFactory;
use Academy\Application\Security\SessionService;
use Academy\Domain\Admissions\ApplicationDraftFactory;
use Academy\Domain\Admissions\ApplicationRepository;
use Academy\Domain\Admissions\ApplicationStateMachine;
use Academy\Domain\Admissions\ApplicationSubmissionPreconditions;
use Academy\Domain\Audit\AuditWriter;
use Academy\Domain\Courses\BatchAvailabilityEvaluator;
use Academy\Domain\Courses\BatchDateValidator;
use Academy\Domain\Courses\BatchRepository;
use Academy\Domain\Courses\CourseDocumentRequirementRepository;
use Academy\Domain\Courses\CourseRepository;
use Academy\Domain\Courses\CourseVersionImmutabilityGuard;
use Academy\Domain\Courses\CourseVersionRepository;
use Academy\Domain\Courses\EligibilityRuleRepository;
use Academy\Domain\Credentials\DocumentFileValidator;
use Academy\Domain\Credentials\DocumentObjectKeyGenerator;
use Academy\Domain\Credentials\DocumentSubmissionRepository;
use Academy\Domain\Credentials\DocumentSubmissionStateMachine;
use Academy\Domain\Credentials\DocumentUploadAuthorizationRepository;
use Academy\Domain\Credentials\MalwareScanner;
use Academy\Domain\Credentials\StuckScanPolicy;
use Academy\Domain\Identity\LearnerProfileRepository;
use Academy\Domain\Identity\LearnerQualificationRepository;
use Academy\Domain\Identity\LegalAcceptancePolicy;
use Academy\Domain\Identity\OtpHmac;
use Academy\Domain\Identity\PasswordResetAuthorizationRepository;
use Academy\Domain\Identity\PersonalProfileValidator;
use Academy\Domain\Identity\ProfessionalProfileValidator;
use Academy\Domain\Identity\ProfileCompletenessCalculator;
use Academy\Domain\Identity\QualificationValidator;
use Academy\Domain\Identity\TokenConfirmationContextRepository;
use Academy\Domain\Identity\TokenConsumedHandler;
use Academy\Domain\Identity\TokenHmac;
use Academy\Domain\Identity\UserSecuritySnapshotRepository;
use Academy\Domain\Identity\UserWriteRepository;
use Academy\Domain\Identity\VerificationChallengeRepository;
use Academy\Domain\Identity\VerificationTokenRepository;
use Academy\Domain\Learning\BatchCapacityPolicy;
use Academy\Domain\Learning\EnrolmentFactory;
use Academy\Domain\Learning\EnrolmentPublicReferenceGenerator;
use Academy\Domain\Learning\EnrolmentRepository;
use Academy\Domain\Learning\EnrolmentStateMachine;
use Academy\Domain\Learning\EnrolmentStatusHistoryRepository;
use Academy\Domain\Notifications\EmailDeliveryPort;
use Academy\Domain\Notifications\NotificationDeliveryRepository;
use Academy\Domain\Notifications\NotificationRetryPolicy;
use Academy\Domain\Notifications\SmsOtpDeliveryPort;
use Academy\Domain\Outbox\OutboxRepository;
use Academy\Domain\Outbox\OutboxTransport;
use Academy\Domain\Outbox\OutboxWriter;
use Academy\Domain\Payments\PaymentGateway;
use Academy\Domain\Payments\PaymentInitiationPolicy;
use Academy\Domain\Payments\PaymentPublicReferenceGenerator;
use Academy\Domain\Payments\PaymentRepository;
use Academy\Domain\Payments\PaymentStateMachine;
use Academy\Domain\Payments\PaymentStatusHistoryRepository;
use Academy\Domain\Payments\SuccessfulPaymentAcceptancePolicy;
use Academy\Domain\Payments\Webhook\PaymentWebhookEventRepository;
use Academy\Domain\Payments\Webhook\WebhookEventNormalizer;
use Academy\Domain\Payments\Webhook\WebhookSignatureVerifier;
use Academy\Domain\RBAC\PermissionRepository;
use Academy\Domain\RBAC\RoleRepository;
use Academy\Domain\Review\ApplicationDecisionPreconditions;
use Academy\Domain\Review\ApplicationReviewAssignmentRepository;
use Academy\Domain\Review\DocumentReviewPolicy;
use Academy\Domain\Review\ReviewerQueueQuery;
use Academy\Domain\Review\ReviewerScopeAssignmentRepository;
use Academy\Domain\Review\ReviewerScopePolicy;
use Academy\Domain\Review\VerificationAuditLogRepository;
use Academy\Domain\Security\RateLimitStore;
use Academy\Domain\Security\SessionRepository;
use Academy\Domain\Storage\ObjectStorage;
use Academy\Http\Controllers\AdminNotificationController;
use Academy\Http\Controllers\ApplicationController;
use Academy\Http\Controllers\BatchController;
use Academy\Http\Controllers\CourseCatalogueController;
use Academy\Http\Controllers\DashboardController;
use Academy\Http\Controllers\DocumentController;
use Academy\Http\Controllers\EmailVerificationController;
use Academy\Http\Controllers\FinancePaymentController;
use Academy\Http\Controllers\ForgotPasswordController;
use Academy\Http\Controllers\HealthController;
use Academy\Http\Controllers\LocalStorageDownloadController;
use Academy\Http\Controllers\LocalUploadController;
use Academy\Http\Controllers\LoginController;
use Academy\Http\Controllers\MobileVerificationController;
use Academy\Http\Controllers\PasswordResetController;
use Academy\Http\Controllers\PaymentController;
use Academy\Http\Controllers\ProfileController;
use Academy\Http\Controllers\QualificationController;
use Academy\Http\Controllers\RazorpayWebhookController;
use Academy\Http\Controllers\RegistrationController;
use Academy\Http\Controllers\ReviewerApplicationController;
use Academy\Http\Controllers\SmokeController;
use Academy\Http\Controllers\Wp01aProbeController;
use Academy\Http\Controllers\Wp01b2aTokenProbeController;
use Academy\Http\Controllers\Wp01bRbacProbeController;
use Academy\Http\Kernel;
use Academy\Http\Middleware\AuthenticationMiddleware;
use Academy\Http\Middleware\CsrfMiddleware;
use Academy\Http\Middleware\ExceptionHandlerMiddleware;
use Academy\Http\Middleware\RateLimitMiddleware;
use Academy\Http\Middleware\RequestIdMiddleware;
use Academy\Http\Middleware\SecurityHeadersMiddleware;
use Academy\Http\Middleware\SessionMiddleware;
use Academy\Http\Middleware\TrustedProxyMiddleware;
use Academy\Http\Routing\RouteAccess;
use Academy\Http\Routing\RouteRequestHandler;
use Academy\Http\Security\ConfirmationCookieSettings;
use Academy\Http\Security\SecurityHeaderPolicy;
use Academy\Http\Security\SessionCookieSettings;
use Academy\Http\Security\TokenPageHeaderPolicy;
use Academy\Infrastructure\Admissions\PdoApplicationRepository;
use Academy\Infrastructure\Audit\PdoAuditWriter;
use Academy\Infrastructure\Courses\PdoBatchRepository;
use Academy\Infrastructure\Courses\PdoCourseDocumentRequirementRepository;
use Academy\Infrastructure\Courses\PdoCourseRepository;
use Academy\Infrastructure\Courses\PdoCourseVersionRepository;
use Academy\Infrastructure\Courses\PdoEligibilityRuleRepository;
use Academy\Infrastructure\Credentials\FakeMalwareScanner;
use Academy\Infrastructure\Credentials\PdoDocumentSubmissionRepository;
use Academy\Infrastructure\Credentials\PdoDocumentUploadAuthorizationRepository;
use Academy\Infrastructure\Credentials\UnconfiguredMalwareScanner;
use Academy\Infrastructure\Database\ConnectionFactory;
use Academy\Infrastructure\Database\TransactionManager;
use Academy\Infrastructure\Identity\PdoLearnerProfileRepository;
use Academy\Infrastructure\Identity\PdoLearnerQualificationRepository;
use Academy\Infrastructure\Identity\PdoPasswordResetAuthorizationRepository;
use Academy\Infrastructure\Identity\PdoTokenConfirmationContextRepository;
use Academy\Infrastructure\Identity\PdoUserSecuritySnapshotRepository;
use Academy\Infrastructure\Identity\PdoUserWriteRepository;
use Academy\Infrastructure\Identity\PdoVerificationChallengeRepository;
use Academy\Infrastructure\Identity\PdoVerificationTokenRepository;
use Academy\Infrastructure\Identity\RecordingTokenConsumedHandler;
use Academy\Infrastructure\Learning\PdoEnrolmentRepository;
use Academy\Infrastructure\Learning\PdoEnrolmentStatusHistoryRepository;
use Academy\Infrastructure\Logging\LoggerFactory;
use Academy\Infrastructure\Notifications\LocalFileEmailAdapter;
use Academy\Infrastructure\Notifications\NotificationKeyMaterial;
use Academy\Infrastructure\Notifications\PdoNotificationDeliveryRepository;
use Academy\Infrastructure\Notifications\RecordingEmailAdapter;
use Academy\Infrastructure\Notifications\RecordingSmsAdapter;
use Academy\Infrastructure\Notifications\SealedSecretBox;
use Academy\Infrastructure\Notifications\UnavailableEmailAdapter;
use Academy\Infrastructure\Notifications\UnavailableSmsAdapter;
use Academy\Infrastructure\Outbox\InMemoryOutboxTransport;
use Academy\Infrastructure\Outbox\PdoOutboxRepository;
use Academy\Infrastructure\Outbox\UnconfiguredOutboxTransport;
use Academy\Infrastructure\Payments\FakePaymentGateway;
use Academy\Infrastructure\Payments\FakeWebhookSigner;
use Academy\Infrastructure\Payments\PdoPaymentRepository;
use Academy\Infrastructure\Payments\PdoPaymentStatusHistoryRepository;
use Academy\Infrastructure\Payments\PdoPaymentWebhookEventRepository;
use Academy\Infrastructure\Payments\RazorpayPaymentGateway;
use Academy\Infrastructure\Payments\RazorpayWebhookEventNormalizer;
use Academy\Infrastructure\Payments\RazorpayWebhookSignatureVerifier;
use Academy\Infrastructure\Payments\UnconfiguredPaymentGateway;
use Academy\Infrastructure\RateLimit\PdoRateLimitStore;
use Academy\Infrastructure\RBAC\PdoPermissionRepository;
use Academy\Infrastructure\RBAC\PdoRoleRepository;
use Academy\Infrastructure\Review\PdoApplicationReviewAssignmentRepository;
use Academy\Infrastructure\Review\PdoReviewerQueueQuery;
use Academy\Infrastructure\Review\PdoReviewerScopeAssignmentRepository;
use Academy\Infrastructure\Review\PdoVerificationAuditLogRepository;
use Academy\Infrastructure\Scheduler\PdoSchedulerLock;
use Academy\Infrastructure\Session\PdoSessionRepository;
use Academy\Infrastructure\Storage\LocalObjectStorage;
use Academy\Infrastructure\Storage\UnconfiguredObjectStorage;
use Academy\Infrastructure\View\Escaper;
use Academy\Infrastructure\View\PhpRenderer;
use DI\ContainerBuilder;
use League\Route\Router;
use League\Route\Strategy\ApplicationStrategy;
use Monolog\Logger;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

return static function (): ContainerInterface {
    $builder = new ContainerBuilder();
    $builder->useAutowiring(true);
    $builder->useAttributes(false);

    $config = require __DIR__ . '/app.php';

    $builder->addDefinitions([
        'config' => $config,
        'config.app' => $config['app'],
        'config.database' => $config['database'],
        'config.logging' => $config['logging'],
        'config.security' => $config['security'],
        'config.paths' => $config['paths'],

        LoggerInterface::class => static function (ContainerInterface $c): LoggerInterface {
            /** @var array{name: string, level: string, path: string, json: bool} $logging */
            $logging = $c->get('config.logging');

            return LoggerFactory::create($logging);
        },
        Logger::class => static fn (ContainerInterface $c): LoggerInterface => $c->get(LoggerInterface::class),

        ConnectionFactory::class => static function (ContainerInterface $c): ConnectionFactory {
            /** @var array{
             *   host: string,
             *   port: int,
             *   name: string,
             *   user: string,
             *   password: string,
             *   charset: string,
             *   options: array<int, mixed>
             * } $database
             */
            $database = $c->get('config.database');

            return new ConnectionFactory($database);
        },

        TransactionManager::class => static fn (ContainerInterface $c): TransactionManager => new TransactionManager(
            $c->get(ConnectionFactory::class),
        ),

        Escaper::class => static fn (): Escaper => new Escaper(),

        PhpRenderer::class => static function (ContainerInterface $c): PhpRenderer {
            /** @var array{templates: string} $paths */
            $paths = $c->get('config.paths');

            return new PhpRenderer($paths['templates'], $c->get(Escaper::class));
        },

        SecurityHeaderPolicy::class => static function (ContainerInterface $c): SecurityHeaderPolicy {
            /** @var array{force_https: bool} $security */
            $security = $c->get('config.security');

            return new SecurityHeaderPolicy($security['force_https']);
        },

        SessionRepository::class => static fn (ContainerInterface $c): SessionRepository => new PdoSessionRepository(
            $c->get(ConnectionFactory::class),
        ),
        CsrfTokenManager::class => static fn (): CsrfTokenManager => new CsrfTokenManager(),
        SessionService::class => static function (ContainerInterface $c): SessionService {
            /** @var array{
             *   session: array{
             *     activity_write_throttle_seconds: int,
             *     timeouts: array{
             *       default: array{idle_seconds: int, absolute_seconds: int},
             *       privileged: array{idle_seconds: int, absolute_seconds: int}
             *     }
             *   }
             * } $security
             */
            $security = $c->get('config.security');

            return new SessionService(
                $c->get(SessionRepository::class),
                $c->get(CsrfTokenManager::class),
                $c->get(LoggerInterface::class),
                $security['session']['timeouts']['default'],
                $security['session']['timeouts']['privileged'],
                $security['session']['activity_write_throttle_seconds'],
            );
        },

        RateLimitKeyFactory::class => static function (ContainerInterface $c): RateLimitKeyFactory {
            /** @var array{rate_limit_pepper: string} $security */
            $security = $c->get('config.security');

            return new RateLimitKeyFactory($security['rate_limit_pepper']);
        },
        RateLimitStore::class => static fn (ContainerInterface $c): RateLimitStore => new PdoRateLimitStore(
            $c->get(ConnectionFactory::class),
        ),
        RateLimiter::class => static function (ContainerInterface $c): RateLimiter {
            /** @var array{rate_limit: array{policies: array<string, array{limit: int, window_seconds: int, failure: string}>}} $security */
            $security = $c->get('config.security');

            return new RateLimiter(
                $c->get(RateLimitStore::class),
                $c->get(RateLimitKeyFactory::class),
                $c->get(LoggerInterface::class),
                $security['rate_limit']['policies'],
            );
        },

        AuditRedactor::class => static fn (): AuditRedactor => new AuditRedactor(),
        AuditWriter::class => static fn (ContainerInterface $c): AuditWriter => new PdoAuditWriter(
            $c->get(ConnectionFactory::class),
        ),
        AuditService::class => static fn (ContainerInterface $c): AuditService => new AuditService(
            $c->get(AuditWriter::class),
            $c->get(AuditRedactor::class),
        ),

        UserSecuritySnapshotRepository::class => static fn (ContainerInterface $c): UserSecuritySnapshotRepository => new PdoUserSecuritySnapshotRepository(
            $c->get(ConnectionFactory::class),
        ),
        UserWriteRepository::class => static fn (ContainerInterface $c): UserWriteRepository => new PdoUserWriteRepository(
            $c->get(ConnectionFactory::class),
        ),
        LearnerProfileRepository::class => static fn (ContainerInterface $c): LearnerProfileRepository => new PdoLearnerProfileRepository(
            $c->get(ConnectionFactory::class),
        ),
        LearnerQualificationRepository::class => static fn (ContainerInterface $c): LearnerQualificationRepository => new PdoLearnerQualificationRepository(
            $c->get(ConnectionFactory::class),
        ),
        PersonalProfileValidator::class => static fn (): PersonalProfileValidator => new PersonalProfileValidator(),
        ProfessionalProfileValidator::class => static fn (): ProfessionalProfileValidator => new ProfessionalProfileValidator(),
        QualificationValidator::class => static fn (): QualificationValidator => new QualificationValidator(),
        ProfileCompletenessCalculator::class => static fn (): ProfileCompletenessCalculator => new ProfileCompletenessCalculator(),
        LearnerProfileService::class => static fn (ContainerInterface $c): LearnerProfileService => new LearnerProfileService(
            $c->get(TransactionManager::class),
            $c->get(LearnerProfileRepository::class),
            $c->get(LearnerQualificationRepository::class),
            $c->get(AuthorizationService::class),
            $c->get(AuditService::class),
            $c->get(PersonalProfileValidator::class),
            $c->get(ProfessionalProfileValidator::class),
            $c->get(ProfileCompletenessCalculator::class),
        ),
        QualificationService::class => static fn (ContainerInterface $c): QualificationService => new QualificationService(
            $c->get(TransactionManager::class),
            $c->get(LearnerProfileRepository::class),
            $c->get(LearnerQualificationRepository::class),
            $c->get(AuthorizationService::class),
            $c->get(AuditService::class),
            $c->get(QualificationValidator::class),
        ),
        CourseRepository::class => static fn (ContainerInterface $c): CourseRepository => new PdoCourseRepository(
            $c->get(ConnectionFactory::class),
        ),
        CourseVersionRepository::class => static fn (ContainerInterface $c): CourseVersionRepository => new PdoCourseVersionRepository(
            $c->get(ConnectionFactory::class),
        ),
        BatchRepository::class => static fn (ContainerInterface $c): BatchRepository => new PdoBatchRepository(
            $c->get(ConnectionFactory::class),
        ),
        EligibilityRuleRepository::class => static fn (ContainerInterface $c): EligibilityRuleRepository => new PdoEligibilityRuleRepository(
            $c->get(ConnectionFactory::class),
        ),
        CourseDocumentRequirementRepository::class => static fn (ContainerInterface $c): CourseDocumentRequirementRepository => new PdoCourseDocumentRequirementRepository(
            $c->get(ConnectionFactory::class),
        ),
        ApplicationRepository::class => static fn (ContainerInterface $c): ApplicationRepository => new PdoApplicationRepository(
            $c->get(ConnectionFactory::class),
        ),
        DocumentSubmissionRepository::class => static fn (ContainerInterface $c): DocumentSubmissionRepository => new PdoDocumentSubmissionRepository(
            $c->get(ConnectionFactory::class),
        ),
        DocumentUploadAuthorizationRepository::class => static fn (ContainerInterface $c): DocumentUploadAuthorizationRepository => new PdoDocumentUploadAuthorizationRepository(
            $c->get(ConnectionFactory::class),
        ),
        ApplicationReviewAssignmentRepository::class => static fn (ContainerInterface $c): ApplicationReviewAssignmentRepository => new PdoApplicationReviewAssignmentRepository(
            $c->get(ConnectionFactory::class),
        ),
        ReviewerScopeAssignmentRepository::class => static fn (ContainerInterface $c): ReviewerScopeAssignmentRepository => new PdoReviewerScopeAssignmentRepository(
            $c->get(ConnectionFactory::class),
        ),
        VerificationAuditLogRepository::class => static fn (ContainerInterface $c): VerificationAuditLogRepository => new PdoVerificationAuditLogRepository(
            $c->get(ConnectionFactory::class),
        ),
        PaymentRepository::class => static fn (ContainerInterface $c): PaymentRepository => new PdoPaymentRepository(
            $c->get(ConnectionFactory::class),
        ),
        PaymentStatusHistoryRepository::class => static fn (ContainerInterface $c): PaymentStatusHistoryRepository => new PdoPaymentStatusHistoryRepository(
            $c->get(ConnectionFactory::class),
        ),
        PaymentStateMachine::class => static fn (): PaymentStateMachine => new PaymentStateMachine(),
        PaymentInitiationPolicy::class => static fn (): PaymentInitiationPolicy => new PaymentInitiationPolicy(),
        PaymentPublicReferenceGenerator::class => static fn (): PaymentPublicReferenceGenerator => new PaymentPublicReferenceGenerator(),
        PaymentGateway::class => static function (ContainerInterface $c): PaymentGateway {
            /** @var array{env: string} $app */
            $app = $c->get('config.app');
            /** @var array{payments: array{fake_gateway_enabled: bool, razorpay_key_id: string, razorpay_key_secret: string}} $security */
            $security = $c->get('config.security');
            $payments = $security['payments'];
            $env = $app['env'];

            if ($payments['fake_gateway_enabled'] && in_array($env, ['local', 'testing', 'ci'], true)) {
                return new FakePaymentGateway($env, true);
            }

            $keyId = trim($payments['razorpay_key_id']);
            $keySecret = trim($payments['razorpay_key_secret']);
            if ($keyId !== '' && $keySecret !== '') {
                return new RazorpayPaymentGateway($keyId, $keySecret);
            }

            return new UnconfiguredPaymentGateway();
        },
        PaymentCheckoutService::class => static fn (ContainerInterface $c): PaymentCheckoutService => new PaymentCheckoutService(
            $c->get(TransactionManager::class),
            $c->get(AuthorizationService::class),
            $c->get(ApplicationRepository::class),
            $c->get(BatchRepository::class),
            $c->get(CourseVersionRepository::class),
            $c->get(PaymentRepository::class),
            $c->get(PaymentStatusHistoryRepository::class),
            $c->get(PaymentInitiationPolicy::class),
            $c->get(PaymentStateMachine::class),
            $c->get(PaymentPublicReferenceGenerator::class),
            $c->get(PaymentGateway::class),
            $c->get(OutboxWriter::class),
            $c->get(AuditService::class),
            $c->get(RateLimiter::class),
            $c->get(EnrolmentRepository::class),
            $c->get(LearnerStatusPresenter::class),
        ),
        FinancePaymentQueryService::class => static fn (ContainerInterface $c): FinancePaymentQueryService => new FinancePaymentQueryService(
            $c->get(AuthorizationService::class),
            $c->get(PaymentRepository::class),
            $c->get(PaymentStatusHistoryRepository::class),
        ),
        EnrolmentRepository::class => static fn (ContainerInterface $c): EnrolmentRepository => new PdoEnrolmentRepository(
            $c->get(ConnectionFactory::class),
        ),
        EnrolmentStatusHistoryRepository::class => static fn (ContainerInterface $c): EnrolmentStatusHistoryRepository => new PdoEnrolmentStatusHistoryRepository(
            $c->get(ConnectionFactory::class),
        ),
        EnrolmentStateMachine::class => static fn (): EnrolmentStateMachine => new EnrolmentStateMachine(),
        EnrolmentFactory::class => static fn (): EnrolmentFactory => new EnrolmentFactory(),
        EnrolmentPublicReferenceGenerator::class => static fn (): EnrolmentPublicReferenceGenerator => new EnrolmentPublicReferenceGenerator(),
        BatchCapacityPolicy::class => static fn (): BatchCapacityPolicy => new BatchCapacityPolicy(),
        SuccessfulPaymentAcceptancePolicy::class => static fn (): SuccessfulPaymentAcceptancePolicy => new SuccessfulPaymentAcceptancePolicy(),
        PaymentWebhookEventRepository::class => static fn (ContainerInterface $c): PaymentWebhookEventRepository => new PdoPaymentWebhookEventRepository(
            $c->get(ConnectionFactory::class),
        ),
        WebhookSignatureVerifier::class => static function (ContainerInterface $c): WebhookSignatureVerifier {
            /** @var array{payments: array{razorpay_webhook_secret: string}} $security */
            $security = $c->get('config.security');

            return new RazorpayWebhookSignatureVerifier($security['payments']['razorpay_webhook_secret']);
        },
        WebhookEventNormalizer::class => static fn (): WebhookEventNormalizer => new RazorpayWebhookEventNormalizer(),
        FakeWebhookSigner::class => static function (ContainerInterface $c): FakeWebhookSigner {
            /** @var array{env: string} $app */
            $app = $c->get('config.app');
            /** @var array{payments: array{fake_gateway_enabled: bool, razorpay_webhook_secret: string}} $security */
            $security = $c->get('config.security');

            return new FakeWebhookSigner(
                $app['env'],
                $security['payments']['fake_gateway_enabled'],
                $security['payments']['razorpay_webhook_secret'] !== ''
                    ? $security['payments']['razorpay_webhook_secret']
                    : 'local-ci-razorpay-webhook-secret-not-for-production',
            );
        },
        SuccessfulPaymentAcceptanceService::class => static fn (ContainerInterface $c): SuccessfulPaymentAcceptanceService => new SuccessfulPaymentAcceptanceService(
            $c->get(ApplicationRepository::class),
            $c->get(PaymentRepository::class),
            $c->get(BatchRepository::class),
            $c->get(CourseVersionRepository::class),
            $c->get(EnrolmentRepository::class),
            $c->get(EnrolmentFactory::class),
            $c->get(EnrolmentPublicReferenceGenerator::class),
            $c->get(EnrolmentStatusHistoryRepository::class),
            $c->get(ApplicationReviewAssignmentRepository::class),
            $c->get(PaymentStateMachine::class),
            $c->get(ApplicationStateMachine::class),
            $c->get(SuccessfulPaymentAcceptancePolicy::class),
            $c->get(BatchCapacityPolicy::class),
            $c->get(PaymentStatusHistoryRepository::class),
            $c->get(OutboxWriter::class),
            $c->get(AuditService::class),
        ),
        RazorpayWebhookIngressService::class => static fn (ContainerInterface $c): RazorpayWebhookIngressService => new RazorpayWebhookIngressService(
            $c->get(WebhookSignatureVerifier::class),
            $c->get(WebhookEventNormalizer::class),
            $c->get(PaymentWebhookEventRepository::class),
            $c->get(OutboxWriter::class),
            $c->get(AuditService::class),
        ),
        PaymentWebhookProcessor::class => static function (ContainerInterface $c): PaymentWebhookProcessor {
            /** @var array{outbox: array{lease_seconds: int, max_attempts: int, backoff_base_seconds: int, backoff_cap_seconds: int}} $security */
            $security = $c->get('config.security');

            return new PaymentWebhookProcessor(
                $c->get(TransactionManager::class),
                $c->get(PaymentWebhookEventRepository::class),
                $c->get(PaymentRepository::class),
                $c->get(PaymentGateway::class),
                $c->get(SuccessfulPaymentAcceptanceService::class),
                $c->get(PaymentStateMachine::class),
                $c->get(PaymentStatusHistoryRepository::class),
                $c->get(AuditService::class),
                $c->get(LoggerInterface::class),
                $security['outbox']['lease_seconds'],
                $security['outbox']['max_attempts'],
                $security['outbox']['backoff_base_seconds'],
                $security['outbox']['backoff_cap_seconds'],
            );
        },
        PaymentReconciliationService::class => static function (ContainerInterface $c): PaymentReconciliationService {
            /** @var array{outbox: array{lease_seconds: int}, payments: array{reconcile_pending_stale_seconds: int}} $security */
            $security = $c->get('config.security');

            return new PaymentReconciliationService(
                $c->get(TransactionManager::class),
                $c->get(AuthorizationService::class),
                $c->get(PaymentRepository::class),
                $c->get(PaymentGateway::class),
                $c->get(SuccessfulPaymentAcceptanceService::class),
                $c->get(PaymentStateMachine::class),
                $c->get(PaymentStatusHistoryRepository::class),
                $c->get(AuditService::class),
                $c->get(LoggerInterface::class),
                $security['outbox']['lease_seconds'],
                $security['payments']['reconcile_pending_stale_seconds'],
            );
        },
        FinanceReconciliationQueryService::class => static fn (ContainerInterface $c): FinanceReconciliationQueryService => new FinanceReconciliationQueryService(
            $c->get(AuthorizationService::class),
            $c->get(PaymentRepository::class),
            $c->get(PaymentStatusHistoryRepository::class),
            $c->get(PaymentWebhookEventRepository::class),
        ),
        ReviewerQueueQuery::class => static fn (ContainerInterface $c): ReviewerQueueQuery => new PdoReviewerQueueQuery(
            $c->get(ConnectionFactory::class),
        ),
        ReviewerScopePolicy::class => static fn (ContainerInterface $c): ReviewerScopePolicy => new ReviewerScopePolicy(
            $c->get(ReviewerScopeAssignmentRepository::class),
            $c->get(CourseVersionRepository::class),
        ),
        DocumentReviewPolicy::class => static fn (): DocumentReviewPolicy => new DocumentReviewPolicy(),
        ApplicationDecisionPreconditions::class => static fn (): ApplicationDecisionPreconditions => new ApplicationDecisionPreconditions(),
        ReviewerAccessGuard::class => static fn (ContainerInterface $c): ReviewerAccessGuard => new ReviewerAccessGuard(
            $c->get(AuthorizationService::class),
            $c->get(ApplicationRepository::class),
            $c->get(ApplicationReviewAssignmentRepository::class),
            $c->get(CourseVersionRepository::class),
            $c->get(ReviewerScopePolicy::class),
        ),
        ApplicationStateMachine::class => static fn (): ApplicationStateMachine => new ApplicationStateMachine(),
        DocumentSubmissionStateMachine::class => static fn (): DocumentSubmissionStateMachine => new DocumentSubmissionStateMachine(),
        DocumentFileValidator::class => static fn (): DocumentFileValidator => new DocumentFileValidator(),
        DocumentObjectKeyGenerator::class => static fn (): DocumentObjectKeyGenerator => new DocumentObjectKeyGenerator(),
        StuckScanPolicy::class => static function (ContainerInterface $c): StuckScanPolicy {
            /** @var array{documents: array{stuck_scan_sla_seconds: int, stuck_scan_max_attempts: int}} $security */
            $security = $c->get('config.security');
            $documents = $security['documents'];

            return new StuckScanPolicy($documents['stuck_scan_sla_seconds'], $documents['stuck_scan_max_attempts']);
        },
        ApplicationSubmissionPreconditions::class => static function (ContainerInterface $c): ApplicationSubmissionPreconditions {
            /** @var array{documents: array{declaration_version: string}} $security */
            $security = $c->get('config.security');

            return new ApplicationSubmissionPreconditions(
                $c->get(ProfileCompletenessCalculator::class),
                $security['documents']['declaration_version'],
            );
        },

        LocalObjectStorage::class => static function (ContainerInterface $c): LocalObjectStorage {
            /** @var array{env: string} $app */
            $app = $c->get('config.app');
            /** @var array{root: string} $paths */
            $paths = $c->get('config.paths');
            /** @var array{documents: array{local_base_path: string, local_signing_secret: string}} $security */
            $security = $c->get('config.security');
            $documents = $security['documents'];
            $basePath = $documents['local_base_path'];
            $absoluteBasePath = ($basePath !== '' && $basePath[0] === '/')
                ? $basePath
                : rtrim($paths['root'], '/') . '/' . ltrim($basePath, '/');

            return new LocalObjectStorage($absoluteBasePath, $documents['local_signing_secret'], $app['env']);
        },
        ObjectStorage::class => static function (ContainerInterface $c): ObjectStorage {
            /** @var array{env: string} $app */
            $app = $c->get('config.app');
            /** @var array{documents: array{storage_driver: string, local_base_path: string, local_signing_secret: string}} $security */
            $security = $c->get('config.security');
            $documents = $security['documents'];

            if ($documents['storage_driver'] === 'local' && in_array($app['env'], ['local', 'testing', 'ci'], true)) {
                return $c->get(LocalObjectStorage::class);
            }

            return new UnconfiguredObjectStorage();
        },
        MalwareScanner::class => static function (ContainerInterface $c): MalwareScanner {
            /** @var array{env: string} $app */
            $app = $c->get('config.app');
            /** @var array{documents: array{fake_scanner_enabled: bool}} $security */
            $security = $c->get('config.security');
            $documents = $security['documents'];

            if ($documents['fake_scanner_enabled'] && in_array($app['env'], ['local', 'testing', 'ci'], true)) {
                return new FakeMalwareScanner($app['env'], $documents['fake_scanner_enabled']);
            }

            return new UnconfiguredMalwareScanner();
        },

        ApplicationWorkspaceService::class => static function (ContainerInterface $c): ApplicationWorkspaceService {
            /** @var array{documents: array{declaration_version: string}} $security */
            $security = $c->get('config.security');

            return new ApplicationWorkspaceService(
                $c->get(AuthorizationService::class),
                $c->get(ApplicationRepository::class),
                $c->get(CourseDocumentRequirementRepository::class),
                $c->get(DocumentSubmissionRepository::class),
                $c->get(LearnerProfileRepository::class),
                $c->get(LearnerQualificationRepository::class),
                $c->get(UserWriteRepository::class),
                $c->get(ApplicationSubmissionPreconditions::class),
                $c->get(ProfileCompletenessCalculator::class),
                $security['documents']['declaration_version'],
            );
        },
        ApplicationDeclarationService::class => static function (ContainerInterface $c): ApplicationDeclarationService {
            /** @var array{documents: array{declaration_version: string}} $security */
            $security = $c->get('config.security');

            return new ApplicationDeclarationService(
                $c->get(TransactionManager::class),
                $c->get(AuthorizationService::class),
                $c->get(ApplicationRepository::class),
                $c->get(AuditService::class),
                $security['documents']['declaration_version'],
            );
        },
        ApplicationSubmitService::class => static fn (ContainerInterface $c): ApplicationSubmitService => new ApplicationSubmitService(
            $c->get(TransactionManager::class),
            $c->get(AuthorizationService::class),
            $c->get(ApplicationRepository::class),
            $c->get(CourseDocumentRequirementRepository::class),
            $c->get(DocumentSubmissionRepository::class),
            $c->get(LearnerProfileRepository::class),
            $c->get(LearnerQualificationRepository::class),
            $c->get(UserWriteRepository::class),
            $c->get(ApplicationSubmissionPreconditions::class),
            $c->get(ApplicationStateMachine::class),
            $c->get(OutboxWriter::class),
            $c->get(AuditService::class),
        ),
        DocumentUploadService::class => static function (ContainerInterface $c): DocumentUploadService {
            /** @var array{env: string} $app */
            $app = $c->get('config.app');
            /** @var array{documents: array{upload_ttl_seconds: int, storage_driver: string}} $security */
            $security = $c->get('config.security');
            $documents = $security['documents'];
            $localUploadUrlOverride = $documents['storage_driver'] === 'local' && in_array($app['env'], ['local', 'testing', 'ci'], true);

            return new DocumentUploadService(
                $c->get(TransactionManager::class),
                $c->get(AuthorizationService::class),
                $c->get(ApplicationRepository::class),
                $c->get(CourseDocumentRequirementRepository::class),
                $c->get(DocumentSubmissionRepository::class),
                $c->get(DocumentUploadAuthorizationRepository::class),
                $c->get(ObjectStorage::class),
                $c->get(DocumentFileValidator::class),
                $c->get(DocumentObjectKeyGenerator::class),
                $c->get(OutboxWriter::class),
                $c->get(AuditService::class),
                $documents['upload_ttl_seconds'],
                $localUploadUrlOverride,
            );
        },
        DocumentDownloadService::class => static function (ContainerInterface $c): DocumentDownloadService {
            /** @var array{documents: array{download_ttl_seconds: int}} $security */
            $security = $c->get('config.security');

            return new DocumentDownloadService(
                $c->get(AuthorizationService::class),
                $c->get(ReviewerAccessGuard::class),
                $c->get(ApplicationRepository::class),
                $c->get(DocumentSubmissionRepository::class),
                $c->get(ObjectStorage::class),
                $security['documents']['download_ttl_seconds'],
            );
        },
        ReviewerClaimService::class => static fn (ContainerInterface $c): ReviewerClaimService => new ReviewerClaimService(
            $c->get(TransactionManager::class),
            $c->get(ReviewerAccessGuard::class),
            $c->get(ApplicationReviewAssignmentRepository::class),
            $c->get(VerificationAuditLogRepository::class),
            $c->get(AuditService::class),
        ),
        DocumentReviewService::class => static fn (ContainerInterface $c): DocumentReviewService => new DocumentReviewService(
            $c->get(TransactionManager::class),
            $c->get(ReviewerAccessGuard::class),
            $c->get(DocumentSubmissionRepository::class),
            $c->get(DocumentReviewPolicy::class),
            $c->get(DocumentSubmissionStateMachine::class),
            $c->get(VerificationAuditLogRepository::class),
            $c->get(AuditService::class),
        ),
        ApplicationCorrectionRequestService::class => static fn (ContainerInterface $c): ApplicationCorrectionRequestService => new ApplicationCorrectionRequestService(
            $c->get(TransactionManager::class),
            $c->get(ReviewerAccessGuard::class),
            $c->get(ApplicationRepository::class),
            $c->get(DocumentSubmissionRepository::class),
            $c->get(DocumentReviewPolicy::class),
            $c->get(DocumentSubmissionStateMachine::class),
            $c->get(ApplicationStateMachine::class),
            $c->get(VerificationAuditLogRepository::class),
            $c->get(OutboxWriter::class),
            $c->get(AuditService::class),
        ),
        ApplicationDecisionService::class => static fn (ContainerInterface $c): ApplicationDecisionService => new ApplicationDecisionService(
            $c->get(TransactionManager::class),
            $c->get(ReviewerAccessGuard::class),
            $c->get(ApplicationRepository::class),
            $c->get(ApplicationReviewAssignmentRepository::class),
            $c->get(CourseDocumentRequirementRepository::class),
            $c->get(DocumentSubmissionRepository::class),
            $c->get(ApplicationDecisionPreconditions::class),
            $c->get(ApplicationStateMachine::class),
            $c->get(VerificationAuditLogRepository::class),
            $c->get(OutboxWriter::class),
            $c->get(AuditService::class),
        ),
        LearnerCorrectionResubmitService::class => static fn (ContainerInterface $c): LearnerCorrectionResubmitService => new LearnerCorrectionResubmitService(
            $c->get(TransactionManager::class),
            $c->get(AuthorizationService::class),
            $c->get(ApplicationRepository::class),
            $c->get(DocumentSubmissionRepository::class),
            $c->get(ApplicationStateMachine::class),
            $c->get(OutboxWriter::class),
            $c->get(AuditService::class),
        ),
        ReviewerApplicationQueryService::class => static fn (ContainerInterface $c): ReviewerApplicationQueryService => new ReviewerApplicationQueryService(
            $c->get(ReviewerAccessGuard::class),
            $c->get(ReviewerQueueQuery::class),
            $c->get(ApplicationReviewAssignmentRepository::class),
            $c->get(CourseVersionRepository::class),
            $c->get(BatchRepository::class),
            $c->get(CourseDocumentRequirementRepository::class),
            $c->get(DocumentSubmissionRepository::class),
            $c->get(LearnerProfileRepository::class),
            $c->get(LearnerQualificationRepository::class),
            $c->get(VerificationAuditLogRepository::class),
        ),
        DocumentScanWorker::class => static function (ContainerInterface $c): DocumentScanWorker {
            /** @var array{outbox: array{lease_seconds: int, max_attempts: int}, documents: array{scan_lease_seconds: int}} $security */
            $security = $c->get('config.security');

            return new DocumentScanWorker(
                $c->get(TransactionManager::class),
                $c->get(DocumentSubmissionRepository::class),
                $c->get(OutboxRepository::class),
                $c->get(MalwareScanner::class),
                $c->get(DocumentSubmissionStateMachine::class),
                $c->get(AuditService::class),
                $c->get(LoggerInterface::class),
                $security['outbox']['lease_seconds'],
                $security['outbox']['max_attempts'],
                $security['documents']['scan_lease_seconds'],
            );
        },
        StuckScanWatchService::class => static fn (ContainerInterface $c): StuckScanWatchService => new StuckScanWatchService(
            $c->get(DocumentSubmissionRepository::class),
            $c->get(OutboxWriter::class),
            $c->get(AuditService::class),
            $c->get(StuckScanPolicy::class),
            $c->get(LoggerInterface::class),
        ),

        BatchAvailabilityEvaluator::class => static fn (): BatchAvailabilityEvaluator => new BatchAvailabilityEvaluator(),
        BatchDateValidator::class => static fn (): BatchDateValidator => new BatchDateValidator(),
        CourseVersionImmutabilityGuard::class => static fn (): CourseVersionImmutabilityGuard => new CourseVersionImmutabilityGuard(),
        ApplicationDraftFactory::class => static fn (): ApplicationDraftFactory => new ApplicationDraftFactory(),
        CatalogueService::class => static fn (ContainerInterface $c): CatalogueService => new CatalogueService(
            $c->get(CourseRepository::class),
            $c->get(CourseVersionRepository::class),
            $c->get(BatchRepository::class),
            $c->get(EligibilityRuleRepository::class),
            $c->get(CourseDocumentRequirementRepository::class),
            $c->get(BatchAvailabilityEvaluator::class),
        ),
        DraftApplicationService::class => static fn (ContainerInterface $c): DraftApplicationService => new DraftApplicationService(
            $c->get(TransactionManager::class),
            $c->get(AuthorizationService::class),
            $c->get(BatchRepository::class),
            $c->get(CourseVersionRepository::class),
            $c->get(CourseRepository::class),
            $c->get(ApplicationRepository::class),
            $c->get(BatchAvailabilityEvaluator::class),
            $c->get(AuditService::class),
        ),
        PasswordHasher::class => static fn (): PasswordHasher => new PasswordHasher(),
        LegalAcceptancePolicy::class => static function (ContainerInterface $c): LegalAcceptancePolicy {
            /** @var array{legal: array{terms_version: string, privacy_version: string}} $security */
            $security = $c->get('config.security');

            return new LegalAcceptancePolicy(
                $security['legal']['terms_version'],
                $security['legal']['privacy_version'],
            );
        },
        InitialApplicantRoleBinder::class => static fn (): InitialApplicantRoleBinder => new InitialApplicantRoleBinder(),
        RegistrationService::class => static fn (ContainerInterface $c): RegistrationService => new RegistrationService(
            $c->get(TransactionManager::class),
            $c->get(UserWriteRepository::class),
            $c->get(LearnerProfileRepository::class),
            $c->get(InitialApplicantRoleBinder::class),
            $c->get(VerificationTokenIssuer::class),
            $c->get(VerificationChallengeIssuer::class),
            $c->get(AuditService::class),
            $c->get(NotificationCapability::class),
            $c->get(RateLimiter::class),
            $c->get(LegalAcceptancePolicy::class),
            $c->get(PasswordHasher::class),
        ),
        EmailVerificationResendService::class => static fn (ContainerInterface $c): EmailVerificationResendService => new EmailVerificationResendService(
            $c->get(TransactionManager::class),
            $c->get(UserWriteRepository::class),
            $c->get(VerificationTokenIssuer::class),
            $c->get(AuditService::class),
            $c->get(NotificationCapability::class),
            $c->get(RateLimiter::class),
        ),
        MobileOtpVerificationService::class => static fn (ContainerInterface $c): MobileOtpVerificationService => new MobileOtpVerificationService(
            $c->get(TransactionManager::class),
            $c->get(UserWriteRepository::class),
            $c->get(VerificationChallengeRepository::class),
            $c->get(OtpHmac::class),
            $c->get(AuditService::class),
            $c->get(RateLimiter::class),
            $c->get(RateLimitKeyFactory::class),
        ),
        MobileOtpResendService::class => static fn (ContainerInterface $c): MobileOtpResendService => new MobileOtpResendService(
            $c->get(TransactionManager::class),
            $c->get(UserWriteRepository::class),
            $c->get(VerificationChallengeIssuer::class),
            $c->get(AuditService::class),
            $c->get(NotificationCapability::class),
            $c->get(RateLimiter::class),
            $c->get(RateLimitKeyFactory::class),
        ),
        LoginService::class => static fn (ContainerInterface $c): LoginService => new LoginService(
            $c->get(TransactionManager::class),
            $c->get(UserWriteRepository::class),
            $c->get(PasswordHasher::class),
            $c->get(AuditService::class),
            $c->get(RateLimiter::class),
        ),
        PostLoginDestinationResolver::class => static fn (ContainerInterface $c): PostLoginDestinationResolver => new PostLoginDestinationResolver(
            $c->get(AuthorizationService::class),
        ),
        LogoutService::class => static fn (ContainerInterface $c): LogoutService => new LogoutService(
            $c->get(SessionService::class),
            $c->get(AuditService::class),
        ),
        ForgotPasswordService::class => static fn (ContainerInterface $c): ForgotPasswordService => new ForgotPasswordService(
            $c->get(TransactionManager::class),
            $c->get(UserWriteRepository::class),
            $c->get(VerificationTokenIssuer::class),
            $c->get(AuditService::class),
            $c->get(NotificationCapability::class),
            $c->get(RateLimiter::class),
        ),
        PasswordResetAuthorizationRepository::class => static fn (ContainerInterface $c): PasswordResetAuthorizationRepository => new PdoPasswordResetAuthorizationRepository(
            $c->get(ConnectionFactory::class),
        ),
        PasswordResetService::class => static function (ContainerInterface $c): PasswordResetService {
            /** @var array{identity_tokens: array{confirmation_context_ttl_seconds: int}} $security */
            $security = $c->get('config.security');

            return new PasswordResetService(
                $c->get(TransactionManager::class),
                $c->get(VerificationTokenRepository::class),
                $c->get(TokenConfirmationContextRepository::class),
                $c->get(PasswordResetAuthorizationRepository::class),
                $c->get(UserWriteRepository::class),
                $c->get(TokenHmac::class),
                $c->get(PasswordHasher::class),
                $c->get(AuditService::class),
                $c->get(RateLimiter::class),
                $c->get(SessionService::class),
                $security['identity_tokens']['confirmation_context_ttl_seconds'],
            );
        },
        RoleRepository::class => static fn (ContainerInterface $c): RoleRepository => new PdoRoleRepository(
            $c->get(ConnectionFactory::class),
        ),
        PermissionRepository::class => static fn (ContainerInterface $c): PermissionRepository => new PdoPermissionRepository(
            $c->get(ConnectionFactory::class),
        ),
        AuthorizationService::class => static fn (ContainerInterface $c): AuthorizationService => new AuthorizationService(
            $c->get(PermissionRepository::class),
        ),
        RoleAssignmentService::class => static fn (ContainerInterface $c): RoleAssignmentService => new RoleAssignmentService(
            $c->get(TransactionManager::class),
            $c->get(RoleRepository::class),
            $c->get(AuditService::class),
            $c->get(SessionService::class),
        ),
        RouteAccess::class => static fn (ContainerInterface $c): RouteAccess => new RouteAccess(
            $c->get(AuthorizationService::class),
        ),

        PdoOutboxRepository::class => static fn (ContainerInterface $c): PdoOutboxRepository => new PdoOutboxRepository(
            $c->get(ConnectionFactory::class),
        ),
        OutboxRepository::class => static fn (ContainerInterface $c): OutboxRepository => $c->get(PdoOutboxRepository::class),
        OutboxWriter::class => static fn (ContainerInterface $c): OutboxWriter => $c->get(PdoOutboxRepository::class),
        OutboxTransport::class => static function (ContainerInterface $c): OutboxTransport {
            /** @var array{env: string} $app */
            $app = $c->get('config.app');
            /** @var array{outbox: array{transport: string}} $security */
            $security = $c->get('config.security');
            $transport = $security['outbox']['transport'];
            if ($transport === 'memory' && in_array($app['env'], ['testing', 'ci', 'local'], true)) {
                return new InMemoryOutboxTransport();
            }

            return new UnconfiguredOutboxTransport();
        },
        OutboxRelayService::class => static function (ContainerInterface $c): OutboxRelayService {
            /** @var array{outbox: array{lease_seconds: int, max_attempts: int, backoff_base_seconds: int, backoff_cap_seconds: int}} $security */
            $security = $c->get('config.security');

            return new OutboxRelayService(
                $c->get(OutboxRepository::class),
                $c->get(OutboxTransport::class),
                $c->get(LoggerInterface::class),
                $security['outbox']['lease_seconds'],
                $security['outbox']['max_attempts'],
                $security['outbox']['backoff_base_seconds'],
                $security['outbox']['backoff_cap_seconds'],
            );
        },

        PdoSchedulerLock::class => static fn (ContainerInterface $c): PdoSchedulerLock => new PdoSchedulerLock(
            $c->get(ConnectionFactory::class),
        ),

        TokenHmac::class => static function (ContainerInterface $c): TokenHmac {
            /** @var array{identity_tokens: array{token_pepper: string}} $security */
            $security = $c->get('config.security');

            return new TokenHmac($security['identity_tokens']['token_pepper']);
        },
        OtpHmac::class => static function (ContainerInterface $c): OtpHmac {
            /** @var array{identity_tokens: array{otp_pepper: string}} $security */
            $security = $c->get('config.security');

            return new OtpHmac($security['identity_tokens']['otp_pepper']);
        },
        NotificationKeyMaterial::class => static function (ContainerInterface $c): NotificationKeyMaterial {
            /** @var array{
             *   notifications: array{
             *     delivery_key: string,
             *     delivery_key_previous: ?string,
             *     delivery_key_version: int,
             *     delivery_key_previous_version: ?int
             *   }
             * } $security
             */
            $security = $c->get('config.security');
            $notifications = $security['notifications'];

            return new NotificationKeyMaterial(
                $notifications['delivery_key'],
                $notifications['delivery_key_version'],
                $notifications['delivery_key_previous'],
                $notifications['delivery_key_previous_version'],
            );
        },
        SealedSecretBox::class => static fn (ContainerInterface $c): SealedSecretBox => new SealedSecretBox(
            $c->get(NotificationKeyMaterial::class),
        ),

        VerificationTokenRepository::class => static fn (ContainerInterface $c): VerificationTokenRepository => new PdoVerificationTokenRepository(
            $c->get(ConnectionFactory::class),
        ),
        VerificationChallengeRepository::class => static fn (ContainerInterface $c): VerificationChallengeRepository => new PdoVerificationChallengeRepository(
            $c->get(ConnectionFactory::class),
        ),
        TokenConfirmationContextRepository::class => static fn (ContainerInterface $c): TokenConfirmationContextRepository => new PdoTokenConfirmationContextRepository(
            $c->get(ConnectionFactory::class),
        ),

        RecordingTokenConsumedHandler::class => static fn (): RecordingTokenConsumedHandler => new RecordingTokenConsumedHandler(),
        TokenConsumedHandler::class => static function (ContainerInterface $c): TokenConsumedHandler {
            /** @var array{env: string} $app */
            $app = $c->get('config.app');

            $handlers = [
                new EmailVerificationTokenConsumedHandler(
                    $c->get(UserWriteRepository::class),
                    $c->get(AuditService::class),
                ),
            ];

            if ($app['env'] === 'testing') {
                $handlers[] = $c->get(RecordingTokenConsumedHandler::class);
            }

            return new CompositeTokenConsumedHandler($handlers);
        },

        RecordingEmailAdapter::class => static fn (): RecordingEmailAdapter => new RecordingEmailAdapter(),
        RecordingSmsAdapter::class => static fn (): RecordingSmsAdapter => new RecordingSmsAdapter(),
        EmailDeliveryPort::class => static function (ContainerInterface $c): EmailDeliveryPort {
            /** @var array{notifications: array{email_adapter: string, local_mail_path: string}} $security */
            $security = $c->get('config.security');
            $adapter = $security['notifications']['email_adapter'];
            if ($adapter === 'recording') {
                return $c->get(RecordingEmailAdapter::class);
            }
            if ($adapter === 'local_file') {
                /** @var array{root: string, storage: string} $paths */
                $paths = $c->get('config.paths');
                $configured = $security['notifications']['local_mail_path'];
                if ($configured !== '' && ($configured[0] === '/' || preg_match('#^[A-Za-z]:[/\\\\]#', $configured) === 1)) {
                    $directory = $configured;
                } elseif (str_starts_with($configured, 'storage/') || str_starts_with($configured, 'storage\\')) {
                    $directory = $paths['root'] . '/' . str_replace('\\', '/', $configured);
                } else {
                    $directory = rtrim($paths['storage'], '/\\') . '/' . ltrim(str_replace('\\', '/', $configured), '/');
                }

                return new LocalFileEmailAdapter($directory);
            }

            return new UnavailableEmailAdapter();
        },
        SmsOtpDeliveryPort::class => static function (ContainerInterface $c): SmsOtpDeliveryPort {
            /** @var array{notifications: array{sms_adapter: string}} $security */
            $security = $c->get('config.security');
            $adapter = $security['notifications']['sms_adapter'];

            return match ($adapter) {
                'recording' => $c->get(RecordingSmsAdapter::class),
                default => new UnavailableSmsAdapter(),
            };
        },
        NotificationCapability::class => static function (ContainerInterface $c): NotificationCapability {
            /** @var array{notifications: array{email_adapter: string, sms_adapter: string}} $security */
            $security = $c->get('config.security');

            return NotificationCapability::fromEnvFlags(
                $security['notifications']['email_adapter'] !== 'unavailable',
                $security['notifications']['sms_adapter'] !== 'unavailable',
            );
        },

        VerificationTokenIssuer::class => static fn (ContainerInterface $c): VerificationTokenIssuer => new VerificationTokenIssuer(
            $c->get(TransactionManager::class),
            $c->get(VerificationTokenRepository::class),
            $c->get(TokenHmac::class),
            $c->get(SealedSecretBox::class),
            $c->get(OutboxWriter::class),
        ),
        VerificationChallengeIssuer::class => static fn (ContainerInterface $c): VerificationChallengeIssuer => new VerificationChallengeIssuer(
            $c->get(TransactionManager::class),
            $c->get(VerificationChallengeRepository::class),
            $c->get(OtpHmac::class),
            $c->get(SealedSecretBox::class),
            $c->get(OutboxWriter::class),
        ),
        TokenConfirmationService::class => static fn (ContainerInterface $c): TokenConfirmationService => new TokenConfirmationService(
            $c->get(TransactionManager::class),
            $c->get(VerificationTokenRepository::class),
            $c->get(TokenConfirmationContextRepository::class),
            $c->get(TokenHmac::class),
            $c->get(TokenConsumedHandler::class),
            $c->get(AuditService::class),
            $c->get(RateLimiter::class),
        ),
        DeliveryFinaliser::class => static function (ContainerInterface $c): DeliveryFinaliser {
            /** @var array{outbox: array{max_attempts: int}} $security */
            $security = $c->get('config.security');

            return new DeliveryFinaliser(
                $c->get(TransactionManager::class),
                $c->get(OutboxRepository::class),
                $c->get(VerificationTokenRepository::class),
                $c->get(VerificationChallengeRepository::class),
                $c->get(AuditService::class),
                $security['outbox']['max_attempts'],
            );
        },
        NotificationDeliveryRepository::class => static fn (ContainerInterface $c): NotificationDeliveryRepository => new PdoNotificationDeliveryRepository(
            $c->get(ConnectionFactory::class),
        ),
        NotificationRetryPolicy::class => static function (ContainerInterface $c): NotificationRetryPolicy {
            /** @var array{outbox: array{max_attempts: int, backoff_base_seconds: int, backoff_cap_seconds: int}} $security */
            $security = $c->get('config.security');

            return new NotificationRetryPolicy(
                maxAttempts: min(5, $security['outbox']['max_attempts']),
                backoffBaseSeconds: max(30, $security['outbox']['backoff_base_seconds']),
                backoffCapSeconds: $security['outbox']['backoff_cap_seconds'],
            );
        },
        LearnerStatusPresenter::class => static fn (): LearnerStatusPresenter => new LearnerStatusPresenter(),
        TransactionalNotificationTemplateRegistry::class => static fn (): TransactionalNotificationTemplateRegistry => new TransactionalNotificationTemplateRegistry(),
        NotificationTemplateRenderer::class => static fn (): NotificationTemplateRenderer => new NotificationTemplateRenderer(),
        NotificationRecipientResolver::class => static function (ContainerInterface $c): NotificationRecipientResolver {
            /** @var array{notifications: array{delivery_key: string}} $security */
            $security = $c->get('config.security');

            return new NotificationRecipientResolver(
                $c->get(UserWriteRepository::class),
                hash('sha256', $security['notifications']['delivery_key'] . '|recipient'),
            );
        },
        NotificationContextResolver::class => static function (ContainerInterface $c): NotificationContextResolver {
            /** @var array{url: string} $app */
            $app = $c->get('config.app');

            return new NotificationContextResolver(
                $c->get(ConnectionFactory::class),
                $c->get(ApplicationRepository::class),
                $c->get(PaymentRepository::class),
                $c->get(EnrolmentRepository::class),
                $c->get(NotificationRecipientResolver::class),
                $app['url'],
                $c->get(LearnerStatusPresenter::class),
            );
        },
        LearnerDashboardQueryService::class => static fn (ContainerInterface $c): LearnerDashboardQueryService => new LearnerDashboardQueryService(
            $c->get(AuthorizationService::class),
            $c->get(ConnectionFactory::class),
            $c->get(LearnerStatusPresenter::class),
        ),
        AdminNotificationQueryService::class => static fn (ContainerInterface $c): AdminNotificationQueryService => new AdminNotificationQueryService(
            $c->get(AuthorizationService::class),
            $c->get(NotificationDeliveryRepository::class),
        ),
        AdminNotificationRetryService::class => static fn (ContainerInterface $c): AdminNotificationRetryService => new AdminNotificationRetryService(
            $c->get(AuthorizationService::class),
            $c->get(NotificationDeliveryRepository::class),
            $c->get(TransactionManager::class),
            $c->get(AuditService::class),
        ),
        TransactionalNotificationDeliveryWorker::class => static function (ContainerInterface $c): TransactionalNotificationDeliveryWorker {
            /** @var array{outbox: array{lease_seconds: int}} $security */
            $security = $c->get('config.security');

            return new TransactionalNotificationDeliveryWorker(
                $c->get(OutboxRepository::class),
                $c->get(NotificationDeliveryRepository::class),
                $c->get(NotificationContextResolver::class),
                $c->get(TransactionalNotificationTemplateRegistry::class),
                $c->get(NotificationTemplateRenderer::class),
                $c->get(EmailDeliveryPort::class),
                $c->get(NotificationRetryPolicy::class),
                $c->get(TransactionManager::class),
                $c->get(AuditService::class),
                $c->get(LoggerInterface::class),
                $security['outbox']['lease_seconds'],
            );
        },
        IdentityNotificationDeliveryWorker::class => static function (ContainerInterface $c): IdentityNotificationDeliveryWorker {
            /** @var array{outbox: array{lease_seconds: int, max_attempts: int, backoff_base_seconds: int, backoff_cap_seconds: int}} $security */
            $security = $c->get('config.security');

            return new IdentityNotificationDeliveryWorker(
                $c->get(OutboxRepository::class),
                $c->get(VerificationTokenRepository::class),
                $c->get(VerificationChallengeRepository::class),
                $c->get(SealedSecretBox::class),
                $c->get(EmailDeliveryPort::class),
                $c->get(SmsOtpDeliveryPort::class),
                $c->get(DeliveryFinaliser::class),
                $c->get(LoggerInterface::class),
                $security['outbox']['lease_seconds'],
                $security['outbox']['max_attempts'],
                $security['outbox']['backoff_base_seconds'],
                $security['outbox']['backoff_cap_seconds'],
            );
        },
        TokenConfirmationCleanupService::class => static fn (ContainerInterface $c): TokenConfirmationCleanupService => new TokenConfirmationCleanupService(
            $c->get(TokenConfirmationContextRepository::class),
        ),

        ConfirmationCookieSettings::class => static function (ContainerInterface $c): ConfirmationCookieSettings {
            /** @var array{identity_tokens: array{use_host_prefix: bool, cookie_secure: bool}} $security */
            $security = $c->get('config.security');

            return ConfirmationCookieSettings::fromEnvFlags(
                $security['identity_tokens']['use_host_prefix'],
                $security['identity_tokens']['cookie_secure'],
            );
        },
        SessionCookieSettings::class => static function (ContainerInterface $c): SessionCookieSettings {
            /** @var array{session: array{cookie_secure: bool, cookies: array{session_name: string, csrf_name: string}}} $security */
            $security = $c->get('config.security');

            return SessionCookieSettings::fromSessionConfig($security['session']);
        },
        TokenPageHeaderPolicy::class => static fn (): TokenPageHeaderPolicy => new TokenPageHeaderPolicy(),

        Router::class => static function (ContainerInterface $c): Router {
            $strategy = new ApplicationStrategy();
            $strategy->setContainer($c);

            $router = new Router();
            $router->setStrategy($strategy);

            $router->get('/health', [HealthController::class, 'handle']);
            $router->get('/smoke', [SmokeController::class, 'handle']);

            $router->get('/register', [RegistrationController::class, 'showForm']);
            $router->post('/register', [RegistrationController::class, 'register']);
            $router->get('/register/pending', [RegistrationController::class, 'pending']);

            $router->get('/login', [LoginController::class, 'showForm']);
            $router->post('/login', [LoginController::class, 'login']);
            $router->post('/logout', [LoginController::class, 'logout']);

            $router->get('/forgot-password', [ForgotPasswordController::class, 'showForm']);
            $router->post('/forgot-password', [ForgotPasswordController::class, 'request']);
            $router->get('/forgot-password/sent', [ForgotPasswordController::class, 'sent']);

            $router->get('/reset-password', [PasswordResetController::class, 'resetPasswordGet']);
            $router->get('/reset-password/confirm', [PasswordResetController::class, 'confirmGet']);
            $router->post('/reset-password/confirm', [PasswordResetController::class, 'confirmPost']);
            $router->get('/reset-password/form', [PasswordResetController::class, 'formGet']);
            $router->post('/reset-password', [PasswordResetController::class, 'complete']);
            $router->get('/reset-password/result', [PasswordResetController::class, 'result']);

            $router->get('/verify-email', [EmailVerificationController::class, 'verifyEmailGet']);
            $router->get('/verify-email/confirm', [EmailVerificationController::class, 'verifyEmailConfirmGet']);
            $router->post('/verify-email/confirm', [EmailVerificationController::class, 'verifyEmailConfirmPost']);
            $router->get('/verify-email/result', [EmailVerificationController::class, 'verifyEmailResult']);
            $router->get('/verify-email/resend', [EmailVerificationController::class, 'resendForm']);
            $router->post('/verify-email/resend', [EmailVerificationController::class, 'resend']);

            $router->get('/verify-mobile', [MobileVerificationController::class, 'showForm']);
            $router->post('/verify-mobile', [MobileVerificationController::class, 'verify']);
            $router->post('/verify-mobile/resend', [MobileVerificationController::class, 'resend']);

            /** @var RouteAccess $profileAccess */
            $profileAccess = $c->get(RouteAccess::class);

            $profileAccess->requirePermission(
                $router->get('/profile', [ProfileController::class, 'overview']),
                'profile.personal.view_own',
            );
            $profileAccess->requirePermission(
                $router->get('/profile/personal', [ProfileController::class, 'showPersonal']),
                'profile.personal.view_own',
            );
            $profileAccess->requirePermission(
                $router->post('/profile/personal', [ProfileController::class, 'updatePersonal']),
                'profile.personal.edit_own',
            );
            $profileAccess->requirePermission(
                $router->get('/profile/professional', [ProfileController::class, 'showProfessional']),
                'profile.professional.view_own',
            );
            $profileAccess->requirePermission(
                $router->post('/profile/professional', [ProfileController::class, 'updateProfessional']),
                'profile.professional.edit_own',
            );
            $profileAccess->requirePermission(
                $router->get('/profile/qualifications', [QualificationController::class, 'index']),
                'profile.professional.view_own',
            );
            $profileAccess->requirePermission(
                $router->post('/profile/qualifications', [QualificationController::class, 'add']),
                'profile.professional.edit_own',
            );
            $profileAccess->requirePermission(
                $router->post('/profile/qualifications/{id}/update', [QualificationController::class, 'update']),
                'profile.professional.edit_own',
            );
            $profileAccess->requirePermission(
                $router->post('/profile/qualifications/{id}/delete', [QualificationController::class, 'delete']),
                'profile.professional.edit_own',
            );

            // Public catalogue + course detail + batch list (WP-02) — no auth gate.
            $router->get('/courses', [CourseCatalogueController::class, 'index']);
            $router->get('/courses/{slug}', [CourseCatalogueController::class, 'show']);
            $router->get('/courses/{slug}/batches', [CourseCatalogueController::class, 'batches']);
            $router->get('/batches/{batchId}', [BatchController::class, 'show']);

            /** @var RouteAccess $dashboardAccess */
            $dashboardAccess = $c->get(RouteAccess::class);
            $dashboardAccess->requirePermission(
                $router->get('/dashboard', [DashboardController::class, 'index']),
                'dashboard.view_own',
            );

            /** @var RouteAccess $notificationAccess */
            $notificationAccess = $c->get(RouteAccess::class);
            $notificationAccess->requirePermission(
                $router->get('/admin/notifications', [AdminNotificationController::class, 'index']),
                'notification.view',
            );
            $notificationAccess->requirePermission(
                $router->get('/admin/notifications/{id}', [AdminNotificationController::class, 'show']),
                'notification.view',
            );
            $notificationAccess->requirePermission(
                $router->post('/admin/notifications/{id}/retry', [AdminNotificationController::class, 'retry']),
                'notification.retry',
            );

            /** @var RouteAccess $applicationAccess */
            $applicationAccess = $c->get(RouteAccess::class);
            $applicationAccess->requirePermission(
                $router->post('/applications', [ApplicationController::class, 'create']),
                'application.create',
            );
            $applicationAccess->requirePermission(
                $router->get('/applications/{id}', [ApplicationController::class, 'show']),
                'application.view_own',
            );
            $applicationAccess->requirePermission(
                $router->get('/applications/{id}/edit', [ApplicationController::class, 'edit']),
                'application.edit_own',
            );
            $applicationAccess->requirePermission(
                $router->post('/applications/{id}', [ApplicationController::class, 'updateDeclaration']),
                'application.edit_own',
            );
            $applicationAccess->requirePermission(
                $router->get('/applications/{id}/documents', [ApplicationController::class, 'documents']),
                'document.view_own',
            );
            $applicationAccess->requirePermission(
                $router->post('/applications/{id}/submit', [ApplicationController::class, 'submit']),
                'application.submit_own',
            );
            $applicationAccess->requirePermission(
                $router->get('/applications/{id}/submission-result', [ApplicationController::class, 'submissionResult']),
                'application.view_own',
            );
            $applicationAccess->requirePermission(
                $router->get('/applications/{id}/corrections', [ApplicationController::class, 'corrections']),
                'application.view_own',
            );
            $applicationAccess->requirePermission(
                $router->post('/applications/{id}/resubmit-corrections', [ApplicationController::class, 'resubmitCorrections']),
                'application.resubmit_corrections_own',
            );

            $applicationAccess->requirePermission(
                $router->get('/applications/{id}/payment', [PaymentController::class, 'show']),
                'payment.view_own',
            );
            $applicationAccess->requirePermission(
                $router->post('/applications/{id}/payments', [PaymentController::class, 'initiate']),
                'payment.initiate_own',
            );
            $applicationAccess->requirePermission(
                $router->get('/applications/{id}/payments/{paymentId}', [PaymentController::class, 'showAttempt']),
                'payment.view_own',
            );
            $applicationAccess->requirePermission(
                $router->post('/applications/{id}/payments/{paymentId}/checkout-return', [PaymentController::class, 'checkoutReturn']),
                'payment.view_own',
            );
            $applicationAccess->requirePermission(
                $router->get('/applications/{id}/payment-result', [PaymentController::class, 'result']),
                'payment.view_own',
            );

            /** @var RouteAccess $financeAccess */
            $financeAccess = $c->get(RouteAccess::class);
            $financeAccess->requirePermission(
                $router->get('/finance/payments', [FinancePaymentController::class, 'index']),
                'finance.payment.view',
            );
            $financeAccess->requirePermission(
                $router->get('/finance/payments/{paymentId}', [FinancePaymentController::class, 'show']),
                'finance.payment.view',
            );

            $router->post('/webhooks/razorpay', [RazorpayWebhookController::class, 'handle']);

            $financeAccess->requirePermission(
                $router->get('/finance/reconciliation', [FinancePaymentController::class, 'reconciliation']),
                'finance.payment.reconcile',
            );
            $financeAccess->requirePermission(
                $router->post('/finance/payments/{paymentId}/reconcile', [FinancePaymentController::class, 'reconcile']),
                'finance.payment.retry_reconciliation',
            );

            /** @var RouteAccess $reviewerAccess */
            $reviewerAccess = $c->get(RouteAccess::class);
            $reviewerAccess->requirePermission(
                $router->get('/reviewer/applications', [ReviewerApplicationController::class, 'queue']),
                'reviewer.queue.view',
            );
            $reviewerAccess->requirePermission(
                $router->get('/reviewer/applications/{id}', [ReviewerApplicationController::class, 'show']),
                'reviewer.application.view',
            );
            $reviewerAccess->requirePermission(
                $router->post('/reviewer/applications/{id}/claim', [ReviewerApplicationController::class, 'claim']),
                'reviewer.application.claim',
            );
            $reviewerAccess->requirePermission(
                $router->post('/reviewer/applications/{id}/release', [ReviewerApplicationController::class, 'release']),
                'reviewer.application.claim',
            );
            $reviewerAccess->requirePermission(
                $router->post('/reviewer/applications/{id}/documents/{submissionId}/verify', [ReviewerApplicationController::class, 'verifyDocument']),
                'reviewer.document.review',
            );
            $reviewerAccess->requirePermission(
                $router->post('/reviewer/applications/{id}/documents/{submissionId}/reject', [ReviewerApplicationController::class, 'rejectDocument']),
                'reviewer.document.review',
            );
            $reviewerAccess->requirePermission(
                $router->post('/reviewer/applications/{id}/documents/{submissionId}/request-resubmission', [ReviewerApplicationController::class, 'requestDocumentResubmission']),
                'reviewer.document.review',
            );
            $reviewerAccess->requirePermission(
                $router->post('/reviewer/applications/{id}/request-correction', [ReviewerApplicationController::class, 'requestCorrection']),
                'reviewer.document.review',
            );
            $reviewerAccess->requirePermission(
                $router->post('/reviewer/applications/{id}/approve', [ReviewerApplicationController::class, 'approve']),
                'reviewer.application.approve',
            );
            $reviewerAccess->requirePermission(
                $router->post('/reviewer/applications/{id}/reject', [ReviewerApplicationController::class, 'reject']),
                'reviewer.application.reject',
            );
            $reviewerAccess->requirePermission(
                $router->get('/reviewer/applications/{id}/documents/{submissionId}/download', [ReviewerApplicationController::class, 'downloadDocument']),
                'reviewer.application.view',
            );

            $applicationAccess->requirePermission(
                $router->post('/applications/{id}/documents/upload-authorizations', [DocumentController::class, 'authorizeUpload']),
                'document.upload_own',
            );
            $applicationAccess->requirePermission(
                $router->post('/applications/{id}/documents/confirm', [DocumentController::class, 'confirm']),
                'document.upload_own',
            );
            $applicationAccess->requirePermission(
                $router->post('/applications/{id}/documents/{submissionId}/replace', [DocumentController::class, 'replace']),
                'document.replace_own',
            );
            $applicationAccess->requirePermission(
                $router->get('/applications/{id}/documents/{submissionId}/download', [DocumentController::class, 'download']),
                'document.view_own',
            );

            /** @var array{env: string} $app */
            $app = $c->get('config.app');

            /** @var array{documents: array{storage_driver: string}} $security */
            $security = $c->get('config.security');
            if ($security['documents']['storage_driver'] === 'local' && in_array($app['env'], ['local', 'testing', 'ci'], true)) {
                // Emulates the client "upload to S3" / "signed GET" steps for local
                // development only — never registered when a real object storage
                // driver is configured (WP03_IMPLEMENTATION_NOTE.md "Storage / scanner").
                $applicationAccess->requirePermission(
                    $router->post('/applications/{id}/documents/local-upload/{authorizationId}', [LocalUploadController::class, 'upload']),
                    'document.upload_own',
                );
                $router->get('/__local-storage/documents/download', [LocalStorageDownloadController::class, 'download']);
            }

            if ($app['env'] === 'testing') {
                $router->get('/__wp01a/probe', [Wp01aProbeController::class, 'probe']);
                $router->post('/__wp01a/probe', [Wp01aProbeController::class, 'probe']);
                $router->get('/__wp01a/protected', [Wp01aProbeController::class, 'protected']);
                $router->post('/__wp01a/limited', [Wp01aProbeController::class, 'limited']);

                /** @var RouteAccess $access */
                $access = $c->get(RouteAccess::class);
                // Probe routes exist only in testing — outside testing they must 404 (not merely auth-deny).
                $access->requirePermission(
                    $router->get('/admin/__wp01b/rbac/allow', [Wp01bRbacProbeController::class, 'allow']),
                    'rbac.role.view',
                );
                $access->requirePermission(
                    $router->get('/admin/__wp01b/rbac/deny', [Wp01bRbacProbeController::class, 'deny']),
                    'document.metadata.view',
                );
                $access->requirePermission(
                    $router->get('/admin/__wp01b/rbac/document', [Wp01bRbacProbeController::class, 'document']),
                    'document.metadata.view',
                );
                $access->requirePermission(
                    $router->get('/admin/__wp01b/rbac/refund', [Wp01bRbacProbeController::class, 'refund']),
                    'finance.refund.approve',
                );
                $access->requirePermission(
                    $router->get('/admin/__wp01b/rbac/item/{id}', [Wp01bRbacProbeController::class, 'parameterized']),
                    'rbac.role.view',
                );
                $access->requirePermission(
                    $router->get('/admin/__wp01b/rbac/unknown', [Wp01bRbacProbeController::class, 'unknown']),
                    'does.not.exist.permission',
                );

                $router->post('/__wp01b2a/issue-token', [Wp01b2aTokenProbeController::class, 'issueProbe']);
            }

            return $router;
        },

        RouteRequestHandler::class => static fn (ContainerInterface $c): RouteRequestHandler => new RouteRequestHandler(
            $c->get(Router::class),
        ),

        HealthController::class => static fn (): HealthController => new HealthController(),
        SmokeController::class => static fn (ContainerInterface $c): SmokeController => new SmokeController(
            $c->get(PhpRenderer::class),
        ),
        Wp01aProbeController::class => static fn (): Wp01aProbeController => new Wp01aProbeController(),
        Wp01bRbacProbeController::class => static fn (): Wp01bRbacProbeController => new Wp01bRbacProbeController(),
        Wp01b2aTokenProbeController::class => static function (ContainerInterface $c): Wp01b2aTokenProbeController {
            /** @var array{identity_tokens: array{confirmation_context_ttl_seconds: int}} $security */
            $security = $c->get('config.security');

            return new Wp01b2aTokenProbeController(
                $c->get(TokenConfirmationService::class),
                $c->get(VerificationTokenIssuer::class),
                $c->get(ConfirmationCookieSettings::class),
                $c->get(TokenPageHeaderPolicy::class),
                $c->get(PhpRenderer::class),
                $security['identity_tokens']['confirmation_context_ttl_seconds'],
            );
        },
        RegistrationController::class => static fn (ContainerInterface $c): RegistrationController => new RegistrationController(
            $c->get(RegistrationService::class),
            $c->get(SessionService::class),
            $c->get(PhpRenderer::class),
        ),
        ProfileController::class => static fn (ContainerInterface $c): ProfileController => new ProfileController(
            $c->get(LearnerProfileService::class),
            $c->get(PhpRenderer::class),
        ),
        QualificationController::class => static fn (ContainerInterface $c): QualificationController => new QualificationController(
            $c->get(QualificationService::class),
            $c->get(PhpRenderer::class),
        ),
        CourseCatalogueController::class => static fn (ContainerInterface $c): CourseCatalogueController => new CourseCatalogueController(
            $c->get(CatalogueService::class),
            $c->get(PhpRenderer::class),
        ),
        BatchController::class => static fn (ContainerInterface $c): BatchController => new BatchController(
            $c->get(CatalogueService::class),
            $c->get(PhpRenderer::class),
        ),
        ApplicationController::class => static fn (ContainerInterface $c): ApplicationController => new ApplicationController(
            $c->get(DraftApplicationService::class),
            $c->get(ApplicationDraftFactory::class),
            $c->get(ApplicationWorkspaceService::class),
            $c->get(ApplicationDeclarationService::class),
            $c->get(ApplicationSubmitService::class),
            $c->get(LearnerCorrectionResubmitService::class),
            $c->get(PhpRenderer::class),
        ),
        PaymentController::class => static fn (ContainerInterface $c): PaymentController => new PaymentController(
            $c->get(PaymentCheckoutService::class),
            $c->get(PhpRenderer::class),
        ),
        FinancePaymentController::class => static fn (ContainerInterface $c): FinancePaymentController => new FinancePaymentController(
            $c->get(FinancePaymentQueryService::class),
            $c->get(FinanceReconciliationQueryService::class),
            $c->get(PaymentReconciliationService::class),
            $c->get(PhpRenderer::class),
        ),
        RazorpayWebhookController::class => static fn (ContainerInterface $c): RazorpayWebhookController => new RazorpayWebhookController(
            $c->get(RazorpayWebhookIngressService::class),
        ),
        ReviewerApplicationController::class => static fn (ContainerInterface $c): ReviewerApplicationController => new ReviewerApplicationController(
            $c->get(ReviewerApplicationQueryService::class),
            $c->get(ReviewerClaimService::class),
            $c->get(DocumentReviewService::class),
            $c->get(ApplicationCorrectionRequestService::class),
            $c->get(ApplicationDecisionService::class),
            $c->get(DocumentDownloadService::class),
            $c->get(PhpRenderer::class),
        ),
        DocumentController::class => static fn (ContainerInterface $c): DocumentController => new DocumentController(
            $c->get(DocumentUploadService::class),
            $c->get(DocumentDownloadService::class),
        ),
        LocalUploadController::class => static fn (ContainerInterface $c): LocalUploadController => new LocalUploadController(
            $c->get(DocumentUploadService::class),
        ),
        LocalStorageDownloadController::class => static fn (ContainerInterface $c): LocalStorageDownloadController => new LocalStorageDownloadController(
            $c->get(LocalObjectStorage::class),
        ),
        DashboardController::class => static fn (ContainerInterface $c): DashboardController => new DashboardController(
            $c->get(LearnerDashboardQueryService::class),
            $c->get(PhpRenderer::class),
        ),
        AdminNotificationController::class => static fn (ContainerInterface $c): AdminNotificationController => new AdminNotificationController(
            $c->get(AdminNotificationQueryService::class),
            $c->get(AdminNotificationRetryService::class),
            $c->get(PhpRenderer::class),
        ),
        LoginController::class => static fn (ContainerInterface $c): LoginController => new LoginController(
            $c->get(LoginService::class),
            $c->get(LogoutService::class),
            $c->get(SessionService::class),
            $c->get(SessionCookieSettings::class),
            $c->get(PhpRenderer::class),
            $c->get(PostLoginDestinationResolver::class),
            $c->get(UserSecuritySnapshotRepository::class),
        ),
        ForgotPasswordController::class => static fn (ContainerInterface $c): ForgotPasswordController => new ForgotPasswordController(
            $c->get(ForgotPasswordService::class),
            $c->get(PhpRenderer::class),
        ),
        PasswordResetController::class => static function (ContainerInterface $c): PasswordResetController {
            /** @var array{identity_tokens: array{confirmation_context_ttl_seconds: int}} $security */
            $security = $c->get('config.security');

            return new PasswordResetController(
                $c->get(TokenConfirmationService::class),
                $c->get(PasswordResetService::class),
                $c->get(ConfirmationCookieSettings::class),
                $c->get(TokenPageHeaderPolicy::class),
                $c->get(PhpRenderer::class),
                $security['identity_tokens']['confirmation_context_ttl_seconds'],
            );
        },
        EmailVerificationController::class => static function (ContainerInterface $c): EmailVerificationController {
            /** @var array{identity_tokens: array{confirmation_context_ttl_seconds: int}} $security */
            $security = $c->get('config.security');

            return new EmailVerificationController(
                $c->get(TokenConfirmationService::class),
                $c->get(EmailVerificationResendService::class),
                $c->get(ConfirmationCookieSettings::class),
                $c->get(TokenPageHeaderPolicy::class),
                $c->get(PhpRenderer::class),
                $security['identity_tokens']['confirmation_context_ttl_seconds'],
            );
        },
        MobileVerificationController::class => static fn (ContainerInterface $c): MobileVerificationController => new MobileVerificationController(
            $c->get(MobileOtpVerificationService::class),
            $c->get(MobileOtpResendService::class),
            $c->get(PhpRenderer::class),
        ),

        TrustedProxyMiddleware::class => static function (ContainerInterface $c): TrustedProxyMiddleware {
            /** @var array{trusted_proxies: list<string>, force_https: bool} $security */
            $security = $c->get('config.security');

            return new TrustedProxyMiddleware($security['trusted_proxies'], $security['force_https']);
        },

        RequestIdMiddleware::class => static fn (): RequestIdMiddleware => new RequestIdMiddleware(),

        ExceptionHandlerMiddleware::class => static function (ContainerInterface $c): ExceptionHandlerMiddleware {
            /** @var array{debug: bool, env: string} $app */
            $app = $c->get('config.app');
            /** @var array{
             *   session: array{
             *     cookie_secure: bool,
             *     cookies: array{session_name: string, csrf_name: string}
             *   }
             * } $security
             */
            $security = $c->get('config.security');

            return new ExceptionHandlerMiddleware(
                $c->get(LoggerInterface::class),
                $c->get(PhpRenderer::class),
                $c->get(SecurityHeaderPolicy::class),
                SessionCookieSettings::fromSessionConfig($security['session']),
                $app['debug'],
                $app['env'],
            );
        },

        SecurityHeadersMiddleware::class => static fn (ContainerInterface $c): SecurityHeadersMiddleware => new SecurityHeadersMiddleware(
            $c->get(SecurityHeaderPolicy::class),
        ),

        SessionMiddleware::class => static function (ContainerInterface $c): SessionMiddleware {
            /** @var array{
             *   session: array{
             *     cookie_secure: bool,
             *     cookies: array{session_name: string, csrf_name: string},
             *     required_path_prefixes: list<string>
             *   }
             * } $security
             */
            $security = $c->get('config.security');

            return new SessionMiddleware(
                $c->get(SessionService::class),
                SessionCookieSettings::fromSessionConfig($security['session']),
                $security['session']['required_path_prefixes'],
            );
        },

        AuthenticationMiddleware::class => static function (ContainerInterface $c): AuthenticationMiddleware {
            /** @var array{
             *   session: array{
             *     cookie_secure: bool,
             *     cookies: array{session_name: string, csrf_name: string}
             *   }
             * } $security
             */
            $security = $c->get('config.security');

            return new AuthenticationMiddleware(
                $c->get(UserSecuritySnapshotRepository::class),
                $c->get(SessionService::class),
                SessionCookieSettings::fromSessionConfig($security['session']),
            );
        },

        CsrfMiddleware::class => static fn (ContainerInterface $c): CsrfMiddleware => new CsrfMiddleware(
            $c->get(SessionService::class),
        ),

        RateLimitMiddleware::class => static function (ContainerInterface $c): RateLimitMiddleware {
            /** @var array{rate_limit: array{path_policies: array<string, string>}} $security */
            $security = $c->get('config.security');

            return new RateLimitMiddleware(
                $c->get(RateLimiter::class),
                pathPolicies: $security['rate_limit']['path_policies'],
            );
        },

        Kernel::class => static function (ContainerInterface $c): Kernel {
            // Observed order (outer → inner): TrustedProxy → RequestId → ExceptionHandler
            // → SecurityHeaders → Session → Authentication → RateLimit → CSRF → Router
            // Permission enforcement is route-level only (RequirePermissionMiddleware via RouteAccess).
            $middleware = [
                $c->get(TrustedProxyMiddleware::class),
                $c->get(RequestIdMiddleware::class),
                $c->get(ExceptionHandlerMiddleware::class),
                $c->get(SecurityHeadersMiddleware::class),
                $c->get(SessionMiddleware::class),
                $c->get(AuthenticationMiddleware::class),
                $c->get(RateLimitMiddleware::class),
                $c->get(CsrfMiddleware::class),
            ];

            return new Kernel($middleware, $c->get(RouteRequestHandler::class));
        },
    ]);

    return $builder->build();
};
