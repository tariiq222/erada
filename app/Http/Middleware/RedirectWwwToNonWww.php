<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RedirectWwwToNonWww
{
    /**
     * Redirect www to non-www domain.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $host = $request->getHost();

        if (str_starts_with($host, 'www.')) {
            $newHost = substr($host, 4);
            $url = $request->getScheme().'://'.$newHost.$request->getRequestUri();

            return redirect()->away($url, 301);
        }

        return $next($request);
    }
}
