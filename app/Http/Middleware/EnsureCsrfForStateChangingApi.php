<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware للتحقق من CSRF Token على طلبات API التي تعدّل البيانات
 *
 * - GET / HEAD / OPTIONS: تمر دون تحقق (الطلبات للقراءة فقط)
 * - POST / PUT / PATCH / DELETE: يجب أن يحتوي الطلب على CSRF token صالح
 *   إما عبر الـ Header (X-XSRF-TOKEN أو X-CSRF-TOKEN) أو عبر حقل _token في الـ body
 * - في بيئة الاختبار فقط: يُقبل header X-Skip-Csrf لتجاوز التحقق (لدعم E2E/Postman)
 * - إذا لم يكن هناك token في الطلب على الإطلاق → 419 (CSRF mismatch)
 * - إذا كانت هناك session نشطة مع token → يجب التطابق
 * - إذا لم تكن هناك session أصلاً (طلب API stateless بدون session middleware)
 *   → 419 (CSRF mismatch). لا يوجد بحالٍ تجاوز على أساس "لا توجد session":
 *   العميل الـ token-only تم التحقق منه في الفرع السابق عبر bearer token،
 *   وأي طلب آخر بدون session بدون token صحيح هو cross-site attack (P0-17).
 */
class EnsureCsrfForStateChangingApi
{
    public const STATUS_CSRF_MISMATCH = 419;

    /**
     * Path prefixes that bypass CSRF entirely.
     *
     * Each entry is matched against the request path as a literal prefix
     * (must include a leading "/", no wildcards). Routes listed here MUST
     * be unauthenticated by design — external clients (e.g. anonymous
     * survey submitters) cannot carry the Sanctum stateful XSRF cookie
     * because they have no session with this app. CSRF protection only
     * applies to authenticated state-changing requests, so opting these
     * out is correct and necessary.
     */
    private const CSRF_EXEMPT_PATH_PREFIXES = [
        'api/surveys/public/',
    ];

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }

        if (app()->environment('testing') && $request->hasHeader('X-Skip-Csrf')) {
            return $next($request);
        }

        // Public, unauthenticated survey-submission endpoints (by-code and
        // by-invitation). External submitters carry no XSRF cookie and there
        // is no session to bind the token to — CSRF does not apply.
        if ($this->isCsrfExemptPath($request->path())) {
            return $next($request);
        }

        // Stateless authenticated clients (Sanctum bearer token) are NOT CSRF
        // bypass targets — they authenticate via Authorization header, not
        // cookies, so cross-origin CSRF is not in their threat model. The
        // previous "no session → skip" branch was reachable by both legitimate
        // bearer clients AND by unauthenticated cross-site requests; rejecting
        // the latter closes P0-17.
        if ($this->hasValidBearerToken($request)) {
            return $next($request);
        }

        $tokens = $this->getCandidateTokens($request);

        if ($tokens === []) {
            return $this->mismatch();
        }

        // API routes do not run the StartSession middleware, so $request->session()
        // throws RuntimeException("Session store not set on request.") when called
        // here. That must surface as a CSRF mismatch (419), not a 500 — the
        // client either forgot to send a session cookie or is a token-less
        // cross-site request; both are rejected by design (P0-17).
        if (! $request->hasSession()) {
            return $this->mismatch();
        }

        $sessionToken = (string) $request->session()->token();
        if ($sessionToken !== '') {
            foreach ($tokens as $candidate) {
                if (hash_equals($sessionToken, $candidate)) {
                    return $next($request);
                }
            }
        }

        return $this->mismatch();
    }

    protected function mismatch(): Response
    {
        return response()->json([
            'message' => 'انتهت صلاحية الجلسة أو رمز CSRF غير صالح. يرجى تحديث الصفحة والمحاولة مرة أخرى.',
        ], self::STATUS_CSRF_MISMATCH);
    }

    /**
     * True when the request path is on the CSRF-exempt allowlist.
     *
     * Matching is a plain string-prefix check on the path returned by
     * $request->path() (no leading "/", no query string). It deliberately
     * does not run on substrings/middleware groups to keep the allowlist
     * easy to audit.
     */
    protected function isCsrfExemptPath(string $path): bool
    {
        foreach (self::CSRF_EXEMPT_PATH_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * True if the request carries a well-formed Sanctum bearer token.
     *
     * We DO NOT verify the token against the database here — that is the
     * auth:sanctum guard's job, which runs downstream of this middleware.
     * We only check that a non-empty Bearer header is present, so that
     * unauthenticated cross-site requests cannot piggy-back on the "stateless
     * client" exemption.
     */
    protected function hasValidBearerToken(Request $request): bool
    {
        // M-14: a bearer synthesized from the auth cookie is a browser/cookie
        // client and MUST still pass the CSRF check — only genuine Authorization-
        // header (token) clients are exempt.
        if ($request->attributes->get('auth_from_cookie') === true) {
            return false;
        }

        $header = $request->header('Authorization');
        if (! is_string($header) || $header === '') {
            return false;
        }

        if (! preg_match('/^Bearer\s+([A-Za-z0-9._~+\-\/=]+)$/', $header, $matches)) {
            return false;
        }

        // Defensive: reject empty tokens that somehow matched the regex
        // (e.g. "Bearer  " with whitespace inside the capture group).
        return $matches[1] !== '';
    }

    /**
     * Collect every raw CSRF token candidate carried by the request.
     *
     * A request may legitimately carry the raw session token in several places,
     * and the SPA additionally sends the ENCRYPTED `XSRF-TOKEN` cookie value in
     * the `X-XSRF-TOKEN` header. We therefore gather both the raw value and the
     * decrypted value (mirroring Laravel's VerifyCsrfToken) and let the caller
     * match any of them against the session token.
     *
     * @return list<string>
     */
    protected function getCandidateTokens(Request $request): array
    {
        $candidates = [];

        // Raw token sources: the form field and the X-CSRF-TOKEN header (meta tag).
        $bodyToken = $request->input('_token');
        if (is_string($bodyToken) && $bodyToken !== '') {
            $candidates[] = $bodyToken;
        }

        $csrfHeader = $request->header('X-CSRF-TOKEN');
        if (is_string($csrfHeader) && $csrfHeader !== '') {
            $candidates[] = $csrfHeader;
        }

        // X-XSRF-TOKEN may be either the raw token (legacy/token clients) or the
        // encrypted XSRF-TOKEN cookie value (browser SPA). Accept both.
        $xsrfHeader = $request->header('X-XSRF-TOKEN');
        if (is_string($xsrfHeader) && $xsrfHeader !== '') {
            $candidates[] = $xsrfHeader;

            try {
                $decrypted = CookieValuePrefix::remove(Crypt::decrypt($xsrfHeader, false));
                if (is_string($decrypted) && $decrypted !== '') {
                    $candidates[] = $decrypted;
                }
            } catch (DecryptException) {
                // Not an encrypted value; the raw candidate above still applies.
            }
        }

        return $candidates;
    }
}
