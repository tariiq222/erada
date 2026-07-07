import React, { Component, ErrorInfo, ReactNode } from 'react';
import {IconAlertTriangle, IconRefresh, IconHome, IconCopy, IconCheck} from '@tabler/icons-react';
import i18n from '@shared/config/i18n';

interface ErrorBoundaryState {
  hasError: boolean;
  error: Error | null;
  errorInfo: ErrorInfo | null;
  errorId: string;
  copied: boolean;
}

interface ErrorBoundaryProps {
  children: ReactNode;
  fallback?: ReactNode;
  onError?: (error: Error, errorInfo: ErrorInfo) => void;
}

// دالة للحصول على build hash إن وجد
const getBuildInfo = (): string => {
  try {
    // يمكن إضافة hash من meta tag أو env
    const meta = document.querySelector('meta[name="build-hash"]');
    return meta?.getAttribute('content') || 'dev';
  } catch {
    return 'unknown';
  }
};

// دالة للحصول على معلومات المسار الحالي
const getRouteInfo = (): string => {
  try {
    return window.location.pathname + window.location.search;
  } catch {
    return 'unknown';
  }
};

// دالة لتوليد معرف فريد للخطأ
const generateErrorId = (): string => {
  return `ERR-${Date.now().toString(36)}-${Math.random().toString(36).substr(2, 5)}`.toUpperCase();
};

// فك تشفير React Error #130
const decodeReactError = (error: Error): string => {
  const message = error.message || '';

  // React Error #130: Element type is invalid
  if (message.includes('#130') || message.includes('Element type is invalid')) {
    return `
خطأ React #130: نوع العنصر غير صالح

الأسباب المحتملة:
1. Component = undefined (import غلط)
2. استخدام named export بدل default export أو العكس
3. Lazy import لا يُرجع default export
4. تمرير متغير غير component إلى render

للتصحيح:
- تأكد من صحة الـ import/export
- تحقق من أن الـ component معرّف قبل استخدامه
- راجع dynamic imports و lazy loading
    `.trim();
  }

  // React Error #31: Objects are not valid as a React child
  if (message.includes('#31') || message.includes('Objects are not valid as a React child')) {
    return `
خطأ React #31: الكائنات غير صالحة كعناصر React

الأسباب المحتملة:
1. محاولة render لـ object بدلاً من string/number/JSX
2. نسيان .toString() أو JSON.stringify()
3. تمرير Promise بدلاً من قيمته

للتصحيح:
- تأكد من أن ما تمرره للـ render هو نص أو رقم أو JSX
- استخدم {JSON.stringify(obj)} للتصحيح
    `.trim();
  }

  return message;
};

class ErrorBoundary extends Component<ErrorBoundaryProps, ErrorBoundaryState> {
  constructor(props: ErrorBoundaryProps) {
    super(props);
    this.state = {
      hasError: false,
      error: null,
      errorInfo: null,
      errorId: '',
      copied: false,
    };
  }

  static getDerivedStateFromError(error: Error): Partial<ErrorBoundaryState> {
    return {
      hasError: true,
      error,
      errorId: generateErrorId(),
    };
  }

  override componentDidCatch(error: Error, errorInfo: ErrorInfo): void {
    this.setState({ errorInfo });

    // استدعاء callback إن وجد
    this.props.onError?.(error, errorInfo);

    // تسجيل الخطأ في console بشكل مفصل
    console.group(`🔴 React Error [${this.state.errorId}]`);
    console.error('Error:', error);
    console.error('Component Stack:', errorInfo.componentStack);
    console.error('Route:', getRouteInfo());
    console.error('Build:', getBuildInfo());
    console.error('User Agent:', navigator.userAgent);
    console.error('Timestamp:', new Date().toISOString());
    console.groupEnd();

    // إرسال إلى نظام التتبع (إن وجد)
    this.reportError(error, errorInfo);

    // Sentry capture — feature-detected so dev without VITE_SENTRY_DSN still works
    void import('@sentry/react').then(({ captureException }) => {
      captureException(error, {
        extra: {
          errorId: this.state.errorId,
          componentStack: errorInfo.componentStack,
          route: getRouteInfo(),
          build: getBuildInfo(),
        },
      });
    });
  }

  private reportError(error: Error, errorInfo: ErrorInfo): void {
    // يمكن إضافة integration مع Sentry أو أي نظام تتبع آخر
    try {
      const errorReport = {
        errorId: this.state.errorId,
        message: error.message,
        stack: error.stack,
        componentStack: errorInfo.componentStack,
        route: getRouteInfo(),
        build: getBuildInfo(),
        userAgent: navigator.userAgent,
        timestamp: new Date().toISOString(),
      };

      // حفظ في localStorage للتصحيح
      const errors = JSON.parse(localStorage.getItem('react_errors') || '[]');
      errors.unshift(errorReport);
      // الاحتفاظ بآخر 10 أخطاء فقط
      localStorage.setItem('react_errors', JSON.stringify(errors.slice(0, 10)));
    } catch {
      // تجاهل أخطاء التخزين
    }
  }

  private getErrorReport(): string {
    const { error, errorInfo, errorId } = this.state;

    return `
=== Error Report ===
ID: ${errorId}
Route: ${getRouteInfo()}
Build: ${getBuildInfo()}
Time: ${new Date().toISOString()}

Error: ${error?.message || 'Unknown'}

Stack Trace:
${error?.stack || 'No stack trace'}

Component Stack:
${errorInfo?.componentStack || 'No component stack'}

User Agent: ${navigator.userAgent}
===================
    `.trim();
  }

  private copyErrorReport = async (): Promise<void> => {
    try {
      await navigator.clipboard.writeText(this.getErrorReport());
      this.setState({ copied: true });
      setTimeout(() => this.setState({ copied: false }), 2000);
    } catch {
      // Fallback
      const textArea = document.createElement('textarea');
      textArea.value = this.getErrorReport();
      document.body.appendChild(textArea);
      textArea.select();
      document.execCommand('copy');
      document.body.removeChild(textArea);
      this.setState({ copied: true });
      setTimeout(() => this.setState({ copied: false }), 2000);
    }
  };

  private handleReload = (): void => {
    window.location.reload();
  };

  private handleGoHome = (): void => {
    window.location.href = '/dashboard';
  };

  override render(): ReactNode {
    if (this.state.hasError) {
      // إذا تم توفير fallback مخصص
      if (this.props.fallback) {
        return this.props.fallback;
      }

      const { error, errorInfo, errorId, copied } = this.state;
      const decodedError = error ? decodeReactError(error) : '';

      return (
        <div className="min-h-screen bg-[var(--surface-subtle)] flex items-center justify-center p-4" dir="rtl">
          <div className="max-w-2xl w-full bg-[var(--surface-base)] rounded-2xl shadow-xl border border-[var(--status-danger-subtle)] overflow-hidden">
            {/* Header */}
            <div className="bg-[var(--status-danger)] px-6 py-4">
              <div className="flex items-center gap-3">
                <div className="h-12 w-12 rounded-xl bg-[var(--text-inverse)]/20 flex items-center justify-center">
                  <IconAlertTriangle className="h-6 w-6 text-[var(--text-inverse)]" />
                </div>
                <div>
                  <h1 className="text-xl font-bold text-[var(--text-inverse)]">{i18n.t('errors.unexpected_error')}</h1>
                  <p className="text-[var(--status-danger)] text-sm">{i18n.t('errors.error_id')}: {errorId}</p>
                </div>
              </div>
            </div>

            {/* Content */}
            <div className="p-6 space-y-4">
              {/* Error Message */}
              <div className="bg-[var(--status-danger-subtle)] border border-[var(--status-danger-subtle)] rounded-xl p-4">
                <h3 className="font-semibold text-[var(--status-danger-text)] mb-2">{i18n.t('errors.error_message')}:</h3>
                <pre className="text-sm text-[var(--status-danger-text)] whitespace-pre-wrap font-mono overflow-x-auto">
                  {decodedError || error?.message || i18n.t('errors.unknown_error')}
                </pre>
              </div>

              {/* Component Stack */}
              {errorInfo?.componentStack && (
                <details className="bg-[var(--surface-subtle)] border border-[var(--border-default)] rounded-xl overflow-hidden">
                  <summary className="px-4 py-3 cursor-pointer font-semibold text-[var(--text-secondary)] hover:bg-[var(--surface-muted)]">
                    {i18n.t('errors.component_stack')}
                  </summary>
                  <pre className="px-4 py-3 text-xs text-[var(--text-secondary)] overflow-x-auto bg-[var(--surface-muted)] border-t border-[var(--border-default)]">
                    {errorInfo.componentStack}
                  </pre>
                </details>
              )}

              {/* Debug Info */}
              <div className="text-xs text-[var(--text-tertiary)] bg-[var(--surface-subtle)] rounded-lg p-3">
                <div className="grid grid-cols-2 gap-2">
                  <div><span className="font-medium">{i18n.t('errors.route')}:</span> {getRouteInfo()}</div>
                  <div><span className="font-medium">{i18n.t('errors.build')}:</span> {getBuildInfo()}</div>
                </div>
              </div>

              {/* Actions */}
              <div className="flex flex-wrap gap-3 pt-4 border-t border-[var(--border-default)]">
                <button
                  onClick={this.handleReload}
                  className="flex items-center gap-2 px-4 py-2 bg-[var(--accent-default)] text-[var(--text-inverse)] rounded-lg hover:bg-[var(--accent-default)] transition-colors"
                >
                  <IconRefresh className="h-4 w-4" />
                  {i18n.t('errors.reload_page')}
                </button>
                <button
                  onClick={this.handleGoHome}
                  className="flex items-center gap-2 px-4 py-2 bg-[var(--surface-muted)] text-[var(--text-secondary)] rounded-lg hover:bg-[var(--surface-muted)] transition-colors"
                >
                  <IconHome className="h-4 w-4" />
                  {i18n.t('errors.go_home')}
                </button>
                <button
                  onClick={this.copyErrorReport}
                  className="flex items-center gap-2 px-4 py-2 bg-[var(--surface-muted)] text-[var(--text-secondary)] rounded-lg hover:bg-[var(--surface-muted)] transition-colors ms-auto"
                >
                  {copied ? (
                    <>
                      <IconCheck className="h-4 w-4 text-[var(--status-success)]" />
                      {i18n.t('errors.copied')}
                    </>
                  ) : (
                    <>
                      <IconCopy className="h-4 w-4" />
                      {i18n.t('errors.copy_report')}
                    </>
                  )}
                </button>
              </div>
            </div>
          </div>
        </div>
      );
    }

    return this.props.children;
  }
}

// HOC للاستخدام مع functional components
export function withErrorBoundary<P extends object>(
  WrappedComponent: React.ComponentType<P>,
  fallback?: ReactNode
): React.FC<P> {
  const displayName = WrappedComponent.displayName || WrappedComponent.name || 'Component';

  const ComponentWithErrorBoundary: React.FC<P> = (props) => (
    <ErrorBoundary fallback={fallback}>
      <WrappedComponent {...props} />
    </ErrorBoundary>
  );

  ComponentWithErrorBoundary.displayName = `withErrorBoundary(${displayName})`;

  return ComponentWithErrorBoundary;
}

// حارس للمكونات الديناميكية
export function SafeComponent({
  component: Component,
  fallback = null,
  ...props
}: {
  component: React.ComponentType<Record<string, unknown>> | undefined | null;
  fallback?: ReactNode;
  [key: string]: unknown;
}): React.ReactElement | null {
  // التحقق من صحة المكون
  if (!Component) {
    console.error('SafeComponent: Component is undefined or null');
    return fallback as React.ReactElement | null;
  }

  if (typeof Component !== 'function' && typeof Component !== 'object') {
    console.error('SafeComponent: Component is not a valid React component type:', typeof Component);
    return fallback as React.ReactElement | null;
  }

  return <Component {...props} />;
}

export default ErrorBoundary;
