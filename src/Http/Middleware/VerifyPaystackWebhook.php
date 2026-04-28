<?php

namespace NoriaLabs\Payments\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use NoriaLabs\Payments\PaystackWebhookVerifier;
use Symfony\Component\HttpFoundation\Response;

class VerifyPaystackWebhook
{
    public function __construct(
        private readonly PaystackWebhookVerifier $verifier,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->verifier->verifyRequest($request)) {
            abort(403, 'Invalid Paystack webhook.');
        }

        return $next($request);
    }
}
