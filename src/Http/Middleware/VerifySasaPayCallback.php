<?php

namespace NoriaLabs\Payments\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use NoriaLabs\Payments\SasaPayCallbackVerifier;
use Symfony\Component\HttpFoundation\Response;

class VerifySasaPayCallback
{
    public function __construct(
        private readonly SasaPayCallbackVerifier $verifier,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->verifier->verifyRequest($request)) {
            abort(403, 'Invalid SasaPay callback.');
        }

        return $next($request);
    }
}
