import { AxiosError } from 'axios';

export interface ApiError {
    message: string;
    errors?: Record<string, string[]>;
    status: number;
    code?: string;
}

/**
 * Parse API error response
 */
export function parseApiError(error: unknown): ApiError {
    if (error instanceof AxiosError) {
        const status = error.response?.status ?? 500;
        const data = error.response?.data;

        // Validation errors (422)
        if (status === 422 && data?.errors) {
            return {
                message: data.message || 'بيانات غير صحيحة',
                errors: data.errors,
                status,
                code: 'VALIDATION_ERROR',
            };
        }

        // Authentication error (401)
        if (status === 401) {
            return {
                message: 'انتهت الجلسة، يرجى تسجيل الدخول مرة أخرى',
                status,
                code: 'UNAUTHORIZED',
            };
        }

        // Forbidden (403)
        if (status === 403) {
            return {
                message: 'غير مصرح لك بهذا الإجراء',
                status,
                code: 'FORBIDDEN',
            };
        }

        // Not found (404)
        if (status === 404) {
            return {
                message: 'العنصر المطلوب غير موجود',
                status,
                code: 'NOT_FOUND',
            };
        }

        // Rate limit (429)
        if (status === 429) {
            return {
                message: 'تم تجاوز الحد المسموح من الطلبات، يرجى المحاولة لاحقاً',
                status,
                code: 'RATE_LIMITED',
            };
        }

        // Server error (500+)
        if (status >= 500) {
            return {
                message: 'حدث خطأ في الخادم، يرجى المحاولة لاحقاً',
                status,
                code: 'SERVER_ERROR',
            };
        }

        // Generic error with message from server
        return {
            message: data?.message || 'حدث خطأ غير متوقع',
            status,
            code: 'UNKNOWN_ERROR',
        };
    }

    // Network error
    if (error instanceof Error && error.message === 'Network Error') {
        return {
            message: 'لا يوجد اتصال بالإنترنت',
            status: 0,
            code: 'NETWORK_ERROR',
        };
    }

    // Unknown error
    return {
        message: error instanceof Error ? error.message : 'حدث خطأ غير متوقع',
        status: 500,
        code: 'UNKNOWN_ERROR',
    };
}

/**
 * Get validation errors as a flat string
 */
export function getValidationErrorsString(errors?: Record<string, string[]>): string {
    if (!errors) return '';

    return Object.values(errors)
        .flat()
        .join('\n');
}

/**
 * Get first validation error for a field
 */
export function getFieldError(
    errors: Record<string, string[]> | undefined,
    field: string
): string | undefined {
    return errors?.[field]?.[0];
}

/**
 * Handle API error with optional callbacks
 */
export function handleApiError(
    error: unknown,
    options: {
        onUnauthorized?: () => void;
        onForbidden?: () => void;
        onNotFound?: () => void;
        onValidationError?: (errors: Record<string, string[]>) => void;
        onNetworkError?: () => void;
        onServerError?: () => void;
        showToast?: (message: string, type: 'error' | 'warning') => void;
    } = {}
): ApiError {
    const apiError = parseApiError(error);

    switch (apiError.code) {
        case 'UNAUTHORIZED':
            options.onUnauthorized?.();
            break;
        case 'FORBIDDEN':
            options.onForbidden?.();
            break;
        case 'NOT_FOUND':
            options.onNotFound?.();
            break;
        case 'VALIDATION_ERROR':
            if (apiError.errors) {
                options.onValidationError?.(apiError.errors);
            }
            break;
        case 'NETWORK_ERROR':
            options.onNetworkError?.();
            break;
        case 'SERVER_ERROR':
            options.onServerError?.();
            break;
    }

    // Show toast if provided
    if (options.showToast) {
        const type = apiError.status >= 500 ? 'error' : 'warning';
        options.showToast(apiError.message, type);
    }

    return apiError;
}

/**
 * Check if error is a specific type
 */
export const isUnauthorizedError = (error: ApiError) => error.code === 'UNAUTHORIZED';
export const isForbiddenError = (error: ApiError) => error.code === 'FORBIDDEN';
export const isNotFoundError = (error: ApiError) => error.code === 'NOT_FOUND';
export const isValidationError = (error: ApiError) => error.code === 'VALIDATION_ERROR';
export const isNetworkError = (error: ApiError) => error.code === 'NETWORK_ERROR';
export const isServerError = (error: ApiError) => error.code === 'SERVER_ERROR';
