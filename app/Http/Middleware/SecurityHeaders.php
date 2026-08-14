<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * NFR-SEC-004: baseline hardening headers applied to every response ahead
 * of the pre-go-live external security audit. HSTS is only ever meaningful
 * over HTTPS, but the header is still safe to send on a plain HTTP response
 * (browsers ignore it there) — sending it unconditionally avoids branching
 * on request scheme, which is unreliable behind a reverse proxy that
 * terminates TLS (TDD-ADR-005: hosting environment is still undecided).
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');

        return $response;
    }
}
