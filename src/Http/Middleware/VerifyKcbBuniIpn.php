<?php

namespace NoriaLabs\Payments\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use NoriaLabs\Payments\KcbBuniIpnVerifier;
use Symfony\Component\HttpFoundation\Response;

/**
 * KCB signs `/till-notification` and `/account-notification` but not
 * `/validation`, which therefore needs `kcb-buni.ipn:no-signature`.
 *
 * The M-PESA Express `callbackUrl` route is not an IPN and is unsigned, so this
 * middleware must not be applied to it.
 */
class VerifyKcbBuniIpn
{
    use ResolvesVerificationFlags;

    public function __construct(
        private readonly KcbBuniIpnVerifier $verifier,
    ) {}

    public function handle(Request $request, Closure $next, string ...$flags): Response
    {
        [$enforceIpWhitelist, $verifySignature] = $this->resolveVerificationFlags($flags);

        if (! $this->verifier->verifyRequest($request, $enforceIpWhitelist, $verifySignature)) {
            abort(403, 'Invalid KCB Buni IPN.');
        }

        return $next($request);
    }
}
