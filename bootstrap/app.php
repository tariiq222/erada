<?php

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\AuthTokenFromCookie;
use App\Http\Middleware\EnsureCsrfForStateChangingApi;
use App\Http\Middleware\EnsureEngineCapability;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\ForceHttpsInProduction;
use App\Http\Middleware\IdempotencyKey;
use App\Http\Middleware\RedirectWwwToNonWww;
use App\Http\Middleware\SanitizeInput;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SessionTimeout;
use App\Http\Middleware\SetLocaleMiddleware;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\ValidatePostSize;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

if (! function_exists('renderApiException')) {
    /**
     * Render a 4xx/5xx JSON envelope for an API request based on the exception type.
     *
     * Returned shapes:
     * - 401 AuthenticationException  → {message}
     * - 422 ValidationException      → {message, errors}
     * - 403 AuthorizationException   → {message, code, request_id}
     * - 404/405 not-found            → {message, code, request_id}
     * - 429 throttle                 → {message, code, retry_after, request_id}
     * - any HttpExceptionInterface   → {message, code, request_id} (4xx only; 5xx falls through)
     * - QueryException               → {message, error_id, request_id} (logged)
     * - fallback (uncaught)          → {message, error_id, request_id} (logged)
     */
    function renderApiException(Throwable $e, Request $request): ?JsonResponse
    {
        $requestId = $request->attributes->get('request_id');

        return match (true) {
            $e instanceof AuthenticationException => renderApiAuthentication(),
            $e instanceof ValidationException => renderApiValidation($e),
            $e instanceof AuthorizationException => renderApiAuthorization($e, $requestId),
            $e instanceof ModelNotFoundException,
            $e instanceof NotFoundHttpException,
            $e instanceof MethodNotAllowedHttpException => renderApiNotFound($e, $requestId),
            $e instanceof ThrottleRequestsException => renderApiThrottle($e, $requestId),
            $e instanceof HttpExceptionInterface => renderApiHttpException($e, $requestId)
                ?? renderApiFallback($e, $requestId),
            $e instanceof QueryException => renderApiQueryException($e, $requestId),
            default => renderApiFallback($e, $requestId),
        };
    }

    function renderApiAuthentication(): JsonResponse
    {
        return response()->json([
            'message' => 'يجب تسجيل الدخول للوصول إلى هذا المورد',
        ], Response::HTTP_UNAUTHORIZED);
    }

    function renderApiValidation(ValidationException $e): JsonResponse
    {
        return response()->json([
            'message' => $e->getMessage(),
            'errors' => $e->errors(),
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    // Laravel $this->authorize() failures. abort(403, '...') flows through
    // renderApiHttpException() so its custom message wins.
    function renderApiAuthorization(AuthorizationException $e, ?string $requestId): JsonResponse
    {
        return response()->json([
            'message' => $e->getMessage() ?: 'ليس لديك صلاحية لهذا الإجراء',
            'code' => 'forbidden',
            'request_id' => $requestId,
        ], Response::HTTP_FORBIDDEN);
    }

    function renderApiNotFound(Throwable $e, ?string $requestId): JsonResponse
    {
        $isMethodNotAllowed = $e instanceof MethodNotAllowedHttpException;

        return response()->json([
            'message' => 'السجل المطلوب غير موجود',
            'code' => $isMethodNotAllowed ? 'method_not_allowed' : 'not_found',
            'request_id' => $requestId,
        ], $isMethodNotAllowed ? Response::HTTP_METHOD_NOT_ALLOWED : Response::HTTP_NOT_FOUND);
    }

    function renderApiThrottle(ThrottleRequestsException $e, ?string $requestId): JsonResponse
    {
        return response()->json([
            'message' => 'لقد تجاوزت الحد المسموح من المحاولات. يرجى الانتظار قبل المحاولة مرة أخرى.',
            'code' => 'too_many_requests',
            'retry_after' => $e->getHeaders()['Retry-After'] ?? 60,
            'request_id' => $requestId,
        ], Response::HTTP_TOO_MANY_REQUESTS);
    }

    // Generic HttpExceptionInterface — abort(401), abort(403, '...'),
    // abort(400), abort(409), etc. Previously only status 403 was special-cased,
    // so abort(401) silently fell through to the 500 fallback. Honor the
    // exception's statusCode + message and emit the unified 4xx envelope
    // `{message, code, request_id}`. Returns null for 5xx so the caller falls
    // through to the QueryException/fallback path.
    function renderApiHttpException(HttpExceptionInterface $e, ?string $requestId): ?JsonResponse
    {
        $status = $e->getStatusCode();

        if ($status < 400 || $status >= 500) {
            return null;
        }

        return response()->json([
            'message' => $e->getMessage() !== '' ? $e->getMessage() : apiDefault4xxMessage($status),
            'code' => apiStatusCodeLabel($status),
            'request_id' => $requestId,
        ], $status);
    }

    function renderApiQueryException(QueryException $e, ?string $requestId): JsonResponse
    {
        $errorId = uniqid('db_', true);
        logger()->error('Database exception', [
            'error_id' => $errorId,
            'message' => $e->getMessage(),
            'sql' => $e->getSql(),
            'user_id' => auth()->id(),
            'request_id' => $requestId,
        ]);

        return response()->json([
            'message' => 'حدث خطأ في قاعدة البيانات',
            'error_id' => $errorId,
            'request_id' => $requestId,
        ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    function renderApiFallback(Throwable $e, ?string $requestId): JsonResponse
    {
        $errorId = uniqid('err_', true);
        logger()->error('Unhandled exception', [
            'error_id' => $errorId,
            'message' => $e->getMessage(),
            'class' => get_class($e),
            'user_id' => auth()->id(),
            'request_id' => $requestId,
            'trace' => $e->getTraceAsString(),
        ]);

        return response()->json([
            'message' => 'حدث خطأ غير متوقع',
            'error_id' => $errorId,
            'request_id' => $requestId,
        ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    /**
     * Human-readable Arabic fallback when an HttpExceptionInterface carries no message.
     */
    function apiDefault4xxMessage(int $status): string
    {
        return match ($status) {
            Response::HTTP_BAD_REQUEST => 'طلب غير صالح',
            Response::HTTP_UNAUTHORIZED => 'يجب تسجيل الدخول للوصول إلى هذا المورد',
            Response::HTTP_FORBIDDEN => 'ليس لديك صلاحية لهذا الإجراء',
            Response::HTTP_NOT_FOUND => 'السجل المطلوب غير موجود',
            Response::HTTP_CONFLICT => 'تعارض في البيانات',
            Response::HTTP_UNPROCESSABLE_ENTITY => 'بيانات غير صالحة للمعالجة',
            default => 'حدث خطأ في الطلب',
        };
    }

    /**
     * Stable machine-readable code for a 4xx status used in the unified envelope.
     */
    function apiStatusCodeLabel(int $status): string
    {
        return match ($status) {
            Response::HTTP_BAD_REQUEST => 'bad_request',
            Response::HTTP_UNAUTHORIZED => 'unauthenticated',
            Response::HTTP_FORBIDDEN => 'forbidden',
            Response::HTTP_NOT_FOUND => 'not_found',
            Response::HTTP_METHOD_NOT_ALLOWED => 'method_not_allowed',
            Response::HTTP_CONFLICT => 'conflict',
            Response::HTTP_UNPROCESSABLE_ENTITY => 'unprocessable_entity',
            Response::HTTP_TOO_MANY_REQUESTS => 'too_many_requests',
            default => 'http_error',
        };
    }
}

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // AssignRequestId must be the FIRST middleware so every subsequent
        // layer (logger, exception handler, Sentry) sees the request_id in
        // shared log context, even if a later middleware throws.
        $middleware->prepend(AssignRequestId::class);

        // TrustProxies: behind Dokploy (or any reverse proxy / load balancer)
        // Request::ip() and Request::isSecure() must read the client-facing
        // values from X-Forwarded-* headers, not the proxy's address or its
        // internal HTTP. Without this, rate-limit keys are wrong, URL
        // generation emits http:// under a TLS-terminating proxy, and CSRF
        // origin checks can misfire. TRUSTED_PROXIES is a comma-separated
        // list of CIDR ranges or "*" to trust everything (Dokploy-internal
        // is fine; for a public-facing proxy prefer the explicit CIDRs).
        $middleware->trustProxies(
            at: env('TRUSTED_PROXIES'),
            headers: Request::HEADER_X_FORWARDED_FOR
                   | Request::HEADER_X_FORWARDED_HOST
                   | Request::HEADER_X_FORWARDED_PORT
                   | Request::HEADER_X_FORWARDED_PROTO
                   | Request::HEADER_X_FORWARDED_AWS_ELB,
        );

        // Global Middleware
        $middleware->prepend(ForceHttpsInProduction::class); // M-18: edge HTTPS redirect
        $middleware->prepend(RedirectWwwToNonWww::class);
        $middleware->append(SecurityHeaders::class);

        // Web Middleware
        $middleware->web(append: [
            SetLocaleMiddleware::class,
        ]);

        // API Middleware - Sanctum stateful SPA authentication
        // statefulApi() يضيف EnsureFrontendRequestsAreStateful تلقائياً
        $middleware->statefulApi();

        $middleware->api(prepend: [
            AuthTokenFromCookie::class, // قراءة Token من Cookie قبل المصادقة
            SanitizeInput::class, // تعقيم المدخلات لمنع XSS
        ]);

        // تطبيق throttle:api (200 req/min) كحد أساسي على جميع مسارات API
        // المسارات ذات الـ throttle الأشد (login: 5/min، sensitive: 30/min) تحتفظ بحدودها الخاصة
        $middleware->api(append: [
            ThrottleRequests::class.':api',
        ]);

        // استثناء جميع مسارات API من CSRF
        // الـ API محمي بـ auth:sanctum (token via HttpOnly cookie) + rate limiting
        // CSRF protection مخصصة لـ web forms وليست ضرورية لـ API مع token auth
        $middleware->validateCsrfTokens(except: [
            'api/*',
        ]);

        // Middleware aliases
        $middleware->alias([
            'auth' => Authenticate::class,
        ]);

        $middleware->api(append: [
            ValidatePostSize::class,
        ]);

        // EnsureUserIsActive: يرفض الطلبات من حسابات مُعطَّلة (is_active=false)
        // يجب أن يعمل بعد AuthTokenFromCookie وقبل أي guard محتاج user()
        $middleware->api(append: [
            EnsureUserIsActive::class,
        ]);

        // SessionTimeout: ينهي الجلسة (يحذف Sanctum token) بعد فترة من عدم النشاط
        // يجب أن يعمل بعد Authenticate حتى يكون $request->user() متاحاً
        // يتجاوز الطلبات غير المصادقة تلقائياً (راجع SessionTimeout::handle)
        $middleware->api(append: [
            SessionTimeout::class,
        ]);
        $middleware->appendToPriorityList(
            after: Authenticate::class,
            append: SessionTimeout::class,
        );

        // EnsureCsrfForStateChangingApi: التحقق من CSRF Token على طلبات API
        // التي تعدّل البيانات (POST/PUT/PATCH/DELETE).
        // يعمل بعد Authenticate (ضمن api append) ليُرجع 401 لغير المصادقين
        // قبل فحص CSRF. يتجاوز العملاء بـ token-only (لا توجد جلسة)
        // وفي بيئة testing يقبل header X-Skip-Csrf.
        $middleware->api(append: [
            EnsureCsrfForStateChangingApi::class,
        ]);

        // Throttle Aliases
        $middleware->alias([
            'throttle.login' => ThrottleRequests::class.':login',
            'throttle.api' => ThrottleRequests::class.':api',
        ]);

        $middleware->alias([
            'idempotency' => IdempotencyKey::class,
            'sanitize' => SanitizeInput::class,
            // Route capability guards delegate canonical Capability constants to
            // AccessDecision::can().
            'engine_capability' => EnsureEngineCapability::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Forward unhandled exceptions to Sentry before the JSON renderer runs.
        // The custom render() below is still invoked and remains authoritative
        // for the client-facing response shape.
        $exceptions->reportable(function (Throwable $e): void {
            if (app()->bound('sentry')) {
                app('sentry')->captureException($e);
            }
        });

        $exceptions->render(function (Throwable $e, Request $request) {
            // احترام الـ response الصريح المُغلَّف داخل HttpResponseException
            // (مثل rate limiters المعرّفة بـ ->response() closure: login/sensitive/admin/delete).
            // بدونه يسقط في الـ fallback ويُرجع 500 بدل الـ 429 المقصود مع Retry-After.
            if ($e instanceof HttpResponseException) {
                return $e->getResponse();
            }

            if (! $request->expectsJson()) {
                return null;
            }

            return renderApiException($e, $request);
        });
    })->create();
