<?php

namespace NoriaLabs\Payments\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use NoriaLabs\Payments\KcbBuniIpnVerifier;
use Symfony\Component\HttpFoundation\Response;

class VerifyKcbBuniIpn
{
    public function __construct(
        private readonly KcbBuniIpnVerifier $verifier,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->verifier->verifyRequest($request)) {
            abort(403, 'Invalid KCB Buni IPN.');
        }

        return $next($request);
    }
}
