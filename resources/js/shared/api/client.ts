/**
 * API Client - النواة الأساسية للتواصل مع الخادم
 *
 * التحسينات الأمنية:
 * - Token يُرسل تلقائياً عبر HttpOnly Cookie (آمن من XSS)
 * - لا تخزين للـ Token في localStorage (تم الإزالة)
 *
 * التحسينات لمنع الحلقات اللانهائية:
 * - Request deduplication للطلبات المتزامنة
 * - منع redirect loops على 401
 * - لا retry تلقائي على أخطاء المصادقة
 */

import type { ApiError } from '@shared/types/api';

const API_BASE_URL = '/api';

// Per-page-load correlation id. Generated lazily on first use; reused for
// every API call from this tab so the server-side log line for any error
// can be matched to the browser-side console / support ticket.
let PAGE_REQUEST_ID: string | null = null;
function getPageRequestId(): string {
  if (PAGE_REQUEST_ID === null) {
    PAGE_REQUEST_ID =
      typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function'
        ? crypto.randomUUID()
        : Math.random().toString(36).slice(2) + Date.now().toString(36);
  }
  return PAGE_REQUEST_ID;
}

type ResponseType = 'json' | 'blob';

interface RequestOptions extends RequestInit {
  data?: unknown;
  responseType?: ResponseType;
  /**
   * Optional caller-supplied X-Idempotency-Key for state-changing requests.
   * When omitted for a mutation, the client auto-generates a stable key for
   * the lifetime of one request() invocation (including 419 CSRF retries).
   * The same key MUST be reused for retries of the same logical intent;
   * distinct mutations MUST get distinct keys.
   */
  idempotencyKey?: string;
}

export type { ApiError };

// الصفحات العامة التي لا تحتاج مصادقة
const PUBLIC_PATHS = ['/login', '/register', '/forgot-password', '/verify-2fa', '/s/', '/design-system'];

// التحقق مما إذا كان المسار الحالي عاماً
function isPublicPath(path: string): boolean {
  return PUBLIC_PATHS.some(publicPath => path.startsWith(publicPath));
}

/**
 * Endpoints whose responses must never be replayed via the idempotency
 * cache because they carry credentials, recovery codes, or one-time tokens.
 * Mirrors the backend's documented intent in
 * app/Http/Middleware/IdempotencyKey.php (SENSITIVE_PATTERNS) plus login and
 * register, which carry credentials and would surprise users on retry
 * (e.g. wrong-password attempt followed by a retry returns the cached
 * "wrong password" response).
 */
const IDEMPOTENCY_EXEMPT_PATTERNS: readonly RegExp[] = [
  /^\/login\/?$/,
  /^\/register\/?$/,
  /^\/2fa\//,
  /^\/password\//,
];

function isIdempotencyExempt(endpoint: string): boolean {
  return IDEMPOTENCY_EXEMPT_PATTERNS.some(pattern => pattern.test(endpoint));
}

/**
 * Generate a stable, sanitization-friendly idempotency key. The backend
 * middleware accepts only [A-Za-z0-9_-] (max 255 chars); we use
 * crypto.randomUUID when available and a v4-shaped fallback otherwise.
 */
function generateIdempotencyKey(): string {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
    return crypto.randomUUID();
  }
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => {
    const r = (Math.random() * 16) | 0;
    const v = c === 'x' ? r : (r & 0x3) | 0x8;
    return v.toString(16);
  });
}

class ApiClient {
  private isAuthenticated: boolean = false;

  // Request deduplication - منع الطلبات المتكررة للـ endpoint نفسه
  private pendingRequests: Map<string, Promise<unknown>> = new Map();

  // منع redirect loops
  private isRedirecting: boolean = false;

  // منع تكرار عملية تجديد CSRF token
  private csrfRefreshPromise: Promise<void> | null = null;

  constructor() {
    this.isAuthenticated = false;
  }

  /**
   * تجديد CSRF cookie عبر Sanctum
   * يُستدعى قبل عمليات تسجيل الدخول وعند حدوث خطأ 419
   */
  async refreshCsrfCookie(): Promise<void> {
    // إذا كان هناك طلب تجديد قائم، أعد نفس الـ Promise
    if (this.csrfRefreshPromise) {
      return this.csrfRefreshPromise;
    }

    this.csrfRefreshPromise = fetch('/sanctum/csrf-cookie', {
      method: 'GET',
      credentials: 'include',
      headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
      },
    }).then(() => {
      // NOTE: Do NOT overwrite the meta csrf-token with the XSRF cookie value.
      // The cookie holds the encrypted session token; the meta tag holds the raw
      // session token from csrf_token() in the blade template. Laravel's
      // VerifyCsrfToken middleware compares X-CSRF-TOKEN directly to the raw
      // session token, so it must remain the raw value, not the encrypted one.
    }).finally(() => {
      this.csrfRefreshPromise = null;
    });

    return this.csrfRefreshPromise;
  }

  /**
   * قراءة CSRF Token من meta tag - يُستدعى في كل طلب للتأكد من الحصول على أحدث قيمة
   */
  private getCsrfToken(): string | null {
    return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || null;
  }

  /**
   * تعيين حالة المصادقة
   * Token يُرسل تلقائياً عبر HttpOnly Cookie من الخادم
   */
  setAuthenticated(authenticated: boolean): void {
    this.isAuthenticated = authenticated;
  }

  /**
   * تعيين Token - محتفظ به للتوافق مع استدعاءات الـ login
   * الـ Cookie يُرسل من الخادم تلقائياً، هذه الدالة تُحدّث حالة isAuthenticated فقط
   */
  setToken(_token: string | null): void {
    if (_token) {
      this.isAuthenticated = true;
    } else {
      this.clearAuth();
    }
  }

  /**
   * مسح حالة المصادقة
   */
  clearAuth(): void {
    this.isAuthenticated = false;
  }

  /**
   * التحقق من حالة المصادقة
   */
  isUserAuthenticated(): boolean {
    return this.isAuthenticated;
  }

  /**
   * إنشاء مفتاح فريد للطلب (للـ deduplication)
   */
  private getRequestKey(endpoint: string, method: string): string {
    return `${method}:${endpoint}`;
  }

  private buildFetchConfig(
    options: RequestOptions = {},
    idempotencyKey: string | null = null,
  ): { config: RequestInit; method: string } {
    const { data, responseType: _responseType, ...customConfig } = options;
    const method = customConfig.method || 'GET';
    const isFormData = typeof FormData !== 'undefined' && data instanceof FormData;

    const headers: HeadersInit = {
      Accept: 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
      'X-Request-Id': getPageRequestId(),
    };

    if (!isFormData) {
      headers['Content-Type'] = 'application/json';
    }

    // إضافة المؤسسة النشطة المختارة من الهيدر (يحترمها الخادم للفلترة)
    const activeOrgId = (() => {
      try {
        return localStorage.getItem('iradah:current_organization_id');
      } catch {
        return null;
      }
    })();
    if (activeOrgId) {
      headers['X-Organization-Id'] = activeOrgId;
    }

    // إضافة CSRF Token للطلبات التي تعدل البيانات (يُقرأ في كل طلب للحصول على أحدث قيمة)
    const csrfToken = this.getCsrfToken();
    if (csrfToken && ['POST', 'PUT', 'PATCH', 'DELETE'].includes(method)) {
      headers['X-CSRF-TOKEN'] = csrfToken;
    }

    // إضافة XSRF-TOKEN header من الـ cookie (يقرأه Laravel تلقائياً)
    const xsrfCookie = document.cookie
      .split('; ')
      .find(row => row.startsWith('XSRF-TOKEN='));
    if (xsrfCookie && ['POST', 'PUT', 'PATCH', 'DELETE'].includes(method)) {
      headers['X-XSRF-TOKEN'] = decodeURIComponent(xsrfCookie.split('=')[1]);
    }

    // معرف الـ idempotency: يُولَّد مرة واحدة لكل طلب متغيّر (POST/PUT/PATCH/DELETE)
    // ويُعاد استخدامه عند إعادة المحاولة (مثلاً بعد 419) حتى لا تتسرّب قيمة جديدة.
    if (idempotencyKey) {
      headers['X-Idempotency-Key'] = idempotencyKey;
    }

    const config: RequestInit = {
      ...customConfig,
      method,
      credentials: 'include', // مهم: إرسال Cookies مع الطلبات (بما في ذلك auth_token)
      headers: {
        ...headers,
        ...customConfig.headers,
      },
    };

    if (data !== undefined) {
      config.body = isFormData ? data : JSON.stringify(data);
    }

    return { config, method };
  }

  private async parseErrorResponse(response: Response, fallbackMessage = 'حدث خطأ'): Promise<ApiError> {
    let errorData: Partial<ApiError> = {};

    try {
      errorData = await response.json();
    } catch {
      errorData = { message: fallbackMessage };
    }

    return {
      status: response.status,
      message: errorData.message || fallbackMessage,
      errors: errorData.errors,
      retry_after: errorData.retry_after,
    };
  }

  private async parseSuccessResponse<T>(response: Response, responseType: ResponseType): Promise<T> {
    if (responseType === 'blob') {
      return await response.blob() as T;
    }

    return await response.json();
  }

  private async request<T>(endpoint: string, options: RequestOptions = {}): Promise<T> {
    const method = options.method || 'GET';
    const responseType = options.responseType || 'json';

    // Request deduplication للطلبات GET فقط
    if (method === 'GET') {
      const requestKey = this.getRequestKey(endpoint, method);
      const pendingRequest = this.pendingRequests.get(requestKey);
      if (pendingRequest) {
        // إرجاع الطلب المعلق بدلاً من بدء طلب جديد
        return pendingRequest as Promise<T>;
      }
    }

    const requestKey = this.getRequestKey(endpoint, method);

    // Idempotency key — يُحسب مرة واحدة لكل طلب متغيّر بحيث تحتفظ
    // إعادة المحاولة (مثل 419) بنفس المفتاح ولا تتسرّب قيمة جديدة.
    const isMutation = ['POST', 'PUT', 'PATCH', 'DELETE'].includes(method);
    const idempotencyKey =
      isMutation && !isIdempotencyExempt(endpoint)
        ? options.idempotencyKey ?? generateIdempotencyKey()
        : null;

    const requestPromise = (async (): Promise<T> => {
      try {
        const { config } = this.buildFetchConfig(options, idempotencyKey);
        const response = await fetch(`${API_BASE_URL}${endpoint}`, config);

        // معالجة 401 Unauthorized
        if (response.status === 401) {
          this.clearAuth();

          // لا نُعيد التوجيه إذا:
          // 1. كنا على صفحات عامة
          // 2. كنا بالفعل في عملية redirect
          const currentPath = window.location.pathname;
          if (!isPublicPath(currentPath) && !this.isRedirecting) {
            this.isRedirecting = true;
            // استخدام replace لمنع إضافة entry في history
            // defer to next tick لمنع تعارض مع React state updates
            setTimeout(() => {
              window.location.replace('/login');
            }, 0);
          }

          // رمي خطأ مع status code للتعامل معه في المستدعي
          const error: ApiError = {
            status: 401,
            message: 'غير مصرح',
          };
          throw error;
        }

        // معالجة 419 CSRF Token Mismatch - إعادة المحاولة بعد تجديد الـ CSRF token
        if (response.status === 419) {
          // محاولة تجديد CSRF cookie وإعادة الطلب مرة واحدة فقط
          try {
            await this.refreshCsrfCookie();
            // إعادة بناء الـ config مع الـ CSRF token الجديد ونفس مفتاح الـ idempotency
            const { config: retryConfig } = this.buildFetchConfig(options, idempotencyKey);
            const retryResponse = await fetch(`${API_BASE_URL}${endpoint}`, retryConfig);

            if (retryResponse.ok) {
              return await this.parseSuccessResponse<T>(retryResponse, responseType);
            }

            // إذا فشلت إعادة المحاولة أيضاً
            if (retryResponse.status === 419 || retryResponse.status === 401) {
              this.clearAuth();
              const currentPath = window.location.pathname;
              if (!isPublicPath(currentPath) && !this.isRedirecting) {
                this.isRedirecting = true;
                setTimeout(() => {
                  window.location.replace('/login');
                }, 0);
              }
              const error: ApiError = {
                status: retryResponse.status,
                message: 'انتهت صلاحية الجلسة. يرجى تسجيل الدخول مرة أخرى.',
              };
              throw error;
            }

            // معالجة أخطاء أخرى من إعادة المحاولة
            if (!retryResponse.ok) {
              throw await this.parseErrorResponse(retryResponse);
            }
            return await this.parseSuccessResponse<T>(retryResponse, responseType);
          } catch (refreshError) {
            // إذا فشل تجديد CSRF نفسه
            if ((refreshError as ApiError).status) {
              throw refreshError;
            }
            this.clearAuth();
            const currentPath = window.location.pathname;
            if (!isPublicPath(currentPath) && !this.isRedirecting) {
              this.isRedirecting = true;
              setTimeout(() => {
                window.location.replace('/login');
              }, 0);
            }
            const error: ApiError = {
              status: 419,
              message: 'انتهت صلاحية الجلسة. يرجى تسجيل الدخول مرة أخرى.',
            };
            throw error;
          }
        }

        // معالجة 429 Too Many Requests
        if (response.status === 429) {
          const error = await this.parseErrorResponse(response, 'لقد تجاوزت الحد المسموح من المحاولات');
          error.retry_after = error.retry_after || 60;
          throw error;
        }

        // معالجة 403 Forbidden - المستخدم مصادق عليه لكن لا يملك صلاحية
        if (response.status === 403) {
          const error = await this.parseErrorResponse(response, 'لا تملك صلاحية لتنفيذ هذا الإجراء');
          throw error;
        }

        if (!response.ok) {
          throw await this.parseErrorResponse(response);
        }

        return await this.parseSuccessResponse<T>(response, responseType);
      } finally {
        // إزالة الطلب من القائمة المعلقة
        if (method === 'GET') {
          this.pendingRequests.delete(requestKey);
        }
      }
    })();

    // حفظ الطلب في القائمة المعلقة (GET فقط)
    if (method === 'GET') {
      this.pendingRequests.set(requestKey, requestPromise);
    }

    return requestPromise;
  }

  get<T>(endpoint: string) {
    return this.request<T>(endpoint, { method: 'GET' });
  }

  post<T>(endpoint: string, data?: unknown, options: RequestOptions = {}) {
    return this.request<T>(endpoint, { method: 'POST', data, ...options });
  }

  put<T>(endpoint: string, data?: unknown, options: RequestOptions = {}) {
    return this.request<T>(endpoint, { method: 'PUT', data, ...options });
  }

  patch<T>(endpoint: string, data?: unknown, options: RequestOptions = {}) {
    return this.request<T>(endpoint, { method: 'PATCH', data, ...options });
  }

  blob(endpoint: string, options: Omit<RequestOptions, 'responseType'> = {}) {
    return this.request<Blob>(endpoint, { ...options, method: options.method || 'GET', responseType: 'blob' });
  }

  delete<T>(endpoint: string, options: RequestOptions = {}) {
    return this.request<T>(endpoint, { method: 'DELETE', ...options });
  }
}

export const api = new ApiClient();
export { API_BASE_URL };
