<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * M-18: redirect plaintext requests to HTTPS in non-local environments. Reads
 * the scheme via the trusted proxy's X-Forwarded-Proto (TrustProxies), so it
 * works behind the load balancer. Never redirects on the local/testing envs.
 */
class ForceHttpsInProduction
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->isSecure($request)
            && ! app()->environment(['local', 'testing'])
            && in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            return redirect()->secure($request->getRequestUri(), 301);
        }

        return $next($request);
    }

    private function isSecure(Request $request): bool
    {
        if ($request->isSecure()) {
            return true;
        }

        $forwardedProto = $request->headers->get('X-Forwarded-Proto', '');

        return in_array('https', array_map('trim', explode(',', strtolower($forwardedProto))), true);
    }
}
