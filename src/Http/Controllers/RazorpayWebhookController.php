<?php

declare(strict_types=1);

namespace Academy\Http\Controllers;

use Academy\Application\Payments\RazorpayWebhookIngressService;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class RazorpayWebhookController
{
    public function __construct(
        private readonly RazorpayWebhookIngressService $ingress,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $rawBody = (string) $request->getBody();
        $signature = $request->getHeaderLine('X-Razorpay-Signature');
        $contentType = $request->getHeaderLine('Content-Type');
        if ($contentType === '') {
            $contentType = null;
        }

        $result = $this->ingress->receive($rawBody, $signature, $contentType);

        return new JsonResponse([
            'ok' => true,
            'duplicate' => $result['duplicate'],
        ], 200);
    }
}
