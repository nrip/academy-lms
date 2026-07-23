<?php

declare(strict_types=1);

namespace Academy\Http\Controllers;

use Academy\Application\Payments\FinancePaymentQueryService;
use Academy\Application\Payments\FinanceReconciliationQueryService;
use Academy\Application\Payments\PaymentReconciliationService;
use Academy\Domain\Exception\AuthenticationException;
use Academy\Domain\Exception\ValidationException;
use Academy\Domain\Payments\PaymentAmountSnapshot;
use Academy\Domain\Security\AuthContext;
use Academy\Http\Middleware\AuthenticationMiddleware;
use Academy\Http\Middleware\SessionMiddleware;
use Academy\Infrastructure\View\PhpRenderer;
use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class FinancePaymentController
{
    public function __construct(
        private readonly FinancePaymentQueryService $financePayments,
        private readonly FinanceReconciliationQueryService $reconciliationQuery,
        private readonly PaymentReconciliationService $reconciliation,
        private readonly PhpRenderer $renderer,
    ) {
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $status = isset($query['status']) && is_string($query['status']) ? $query['status'] : null;
        $publicReference = isset($query['public_reference']) && is_string($query['public_reference'])
            ? $query['public_reference']
            : null;
        $providerOrderId = isset($query['provider_order_id']) && is_string($query['provider_order_id'])
            ? $query['provider_order_id']
            : null;
        $applicationId = isset($query['application_id']) && $query['application_id'] !== ''
            ? (int) $query['application_id']
            : null;

        $page = $this->financePayments->list(
            $this->auth($request),
            $status,
            $publicReference,
            $providerOrderId,
            $applicationId,
        );

        $html = $this->renderer->render('pages/finance/payments', [
            'title' => 'Payments',
            'csrf' => $this->csrf($request),
            'page' => $page,
            'filters' => [
                'status' => $status,
                'public_reference' => $publicReference,
                'provider_order_id' => $providerOrderId,
                'application_id' => $applicationId,
            ],
        ]);

        return new HtmlResponse($html);
    }

    /**
     * @param array<string, string> $args
     */
    public function show(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $paymentId = (int) ($args['paymentId'] ?? 0);
        $detail = $this->financePayments->detail($this->auth($request), $paymentId);
        $payment = $detail['payment'];

        $html = $this->renderer->render('pages/finance/payment_detail', [
            'title' => 'Payment detail',
            'csrf' => $this->csrf($request),
            'payment' => $payment,
            'history' => $detail['history'],
            'amountDisplay' => PaymentAmountSnapshot::minorToDecimal($payment->amountMinor),
            'baseDisplay' => PaymentAmountSnapshot::minorToDecimal($payment->baseFeeMinor),
            'gstDisplay' => PaymentAmountSnapshot::minorToDecimal($payment->gstMinor),
        ]);

        return new HtmlResponse($html);
    }

    public function reconciliation(ServerRequestInterface $request): ResponseInterface
    {
        $overview = $this->reconciliationQuery->overview($this->auth($request));
        $html = $this->renderer->render('pages/finance/reconciliation', [
            'title' => 'Payment reconciliation',
            'csrf' => $this->csrf($request),
            'overview' => $overview,
        ]);

        return new HtmlResponse($html);
    }

    /**
     * @param array<string, string> $args
     */
    public function reconcile(ServerRequestInterface $request, array $args): ResponseInterface
    {
        $paymentId = (int) ($args['paymentId'] ?? 0);
        $body = $request->getParsedBody();
        $reason = is_array($body) && isset($body['reason']) && is_string($body['reason'])
            ? trim($body['reason'])
            : '';

        try {
            $this->reconciliation->retryByFinance($this->auth($request), $paymentId, $reason);
        } catch (ValidationException) {
            return new RedirectResponse('/finance/payments/' . $paymentId . '?error=reason', 303);
        }

        return new RedirectResponse('/finance/payments/' . $paymentId . '?reconciled=1', 303);
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
