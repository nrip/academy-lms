<?php

declare(strict_types=1);

namespace Academy\Http\Controllers;

use Academy\Application\Payments\PaymentCheckoutService;
use Academy\Domain\Exception\AuthenticationException;
use Academy\Domain\Exception\ConflictException;
use Academy\Domain\Exception\DomainRuleException;
use Academy\Domain\Exception\ExternalServiceException;
use Academy\Domain\Exception\RateLimitExceededException;
use Academy\Domain\Payments\PaymentAmountSnapshot;
use Academy\Domain\Security\AuthContext;
use Academy\Http\Middleware\AuthenticationMiddleware;
use Academy\Http\Middleware\SessionMiddleware;
use Academy\Infrastructure\View\PhpRenderer;
use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class PaymentController
{
    public function __construct(
        private readonly PaymentCheckoutService $checkout,
        private readonly PhpRenderer $renderer,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function show(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $applicationId = (int) ($args['id'] ?? 0);
        $view = $this->checkout->getCheckoutPage($this->auth($request), $applicationId);
        $query = $request->getQueryParams();

        $html = $this->renderer->render('pages/applications/payment', [
            'title' => 'Application payment',
            'csrf' => $this->csrf($request),
            'view' => $view,
            'error' => isset($query['error']) ? (string) $query['error'] : null,
        ]);

        return new HtmlResponse($html);
    }

    /**
     * @param array<string, string> $args
     */
    public function initiate(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $applicationId = (int) ($args['id'] ?? 0);
        $wantsJson = $this->wantsJson($request);

        try {
            $payment = $this->checkout->initiate($this->auth($request), $applicationId);
        } catch (ConflictException $exception) {
            return $this->initiateError($request, $applicationId, $exception->getMessage(), 409, $wantsJson);
        } catch (DomainRuleException $exception) {
            return $this->initiateError($request, $applicationId, $exception->getMessage(), 422, $wantsJson);
        } catch (RateLimitExceededException $exception) {
            return $this->initiateError($request, $applicationId, 'Too many payment attempts. Try again later.', 429, $wantsJson);
        } catch (ExternalServiceException) {
            return $this->initiateError(
                $request,
                $applicationId,
                'Payment provider is temporarily unavailable. You can retry shortly.',
                503,
                $wantsJson,
            );
        }

        if ($wantsJson) {
            return new JsonResponse([
                'payment_id' => $payment->paymentId,
                'public_reference' => $payment->publicReference,
                'provider_order_id' => $payment->providerOrderId,
                'amount_minor' => $payment->amountMinor,
                'currency' => $payment->currency,
                'base_fee_display' => PaymentAmountSnapshot::minorToDecimal($payment->baseFeeMinor),
                'gst_display' => PaymentAmountSnapshot::minorToDecimal($payment->gstMinor),
                'total_display' => PaymentAmountSnapshot::minorToDecimal($payment->amountMinor),
                'status' => $payment->status,
                'gateway_key_id' => $this->checkout->getCheckoutPage($this->auth($request), $applicationId)->gatewayPublicKeyId,
            ], 201);
        }

        return new RedirectResponse(
            '/applications/' . $applicationId . '/payments/' . $payment->paymentId,
            303,
        );
    }

    /**
     * @param array<string, string> $args
     */
    public function showAttempt(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $applicationId = (int) ($args['id'] ?? 0);
        $paymentId = (int) ($args['paymentId'] ?? 0);
        $payment = $this->checkout->getPayment($this->auth($request), $applicationId, $paymentId);
        $page = $this->checkout->getCheckoutPage($this->auth($request), $applicationId);

        $html = $this->renderer->render('pages/applications/payment_attempt', [
            'title' => 'Complete payment',
            'csrf' => $this->csrf($request),
            'application' => $page->application,
            'payment' => $payment,
            'gatewayPublicKeyId' => $page->gatewayPublicKeyId,
        ]);

        return new HtmlResponse($html);
    }

    /**
     * Informational browser return — never marks payment successful.
     *
     * @param array<string, string> $args
     */
    public function checkoutReturn(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $applicationId = (int) ($args['id'] ?? 0);
        $paymentId = (int) ($args['paymentId'] ?? 0);

        try {
            $this->checkout->recordCheckoutReturn($this->auth($request), $applicationId, $paymentId);
        } catch (ConflictException) {
            // Still redirect to result; authoritative confirmation is WP-06.
        }

        return new RedirectResponse('/applications/' . $applicationId . '/payment-result', 303);
    }

    /**
     * @param array<string, string> $args
     */
    public function result(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $applicationId = (int) ($args['id'] ?? 0);
        $view = $this->checkout->getPaymentResult($this->auth($request), $applicationId);

        $html = $this->renderer->render('pages/applications/payment_result', [
            'title' => 'Payment status',
            'csrf' => $this->csrf($request),
            'view' => $view,
        ]);

        return new HtmlResponse($html);
    }

    private function initiateError(
        ServerRequestInterface $request,
        int $applicationId,
        string $message,
        int $status,
        bool $wantsJson,
    ): ResponseInterface {
        if ($wantsJson) {
            return new JsonResponse(['error' => $message], $status);
        }

        if ($status === 409 || $status === 422) {
            $view = $this->checkout->getCheckoutPage($this->auth($request), $applicationId);
            $html = $this->renderer->render('pages/applications/payment', [
                'title' => 'Application payment',
                'csrf' => $this->csrf($request),
                'view' => $view,
                'error' => $message,
            ]);

            return new HtmlResponse($html, $status);
        }

        return new RedirectResponse(
            '/applications/' . $applicationId . '/payment?error=' . rawurlencode($message),
            303,
        );
    }

    private function wantsJson(ServerRequestInterface $request): bool
    {
        $accept = $request->getHeaderLine('Accept');
        $contentType = $request->getHeaderLine('Content-Type');

        return str_contains($accept, 'application/json')
            || str_contains($contentType, 'application/json');
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
        $token = $request->getAttribute(SessionMiddleware::ATTR_RAW_CSRF);

        return is_string($token) ? $token : '';
    }
}
