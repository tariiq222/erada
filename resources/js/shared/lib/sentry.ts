import * as Sentry from '@sentry/react';

const SENTRY_DSN = import.meta.env.VITE_SENTRY_DSN as string | undefined;

let initialized = false;

/**
 * Initialize Sentry on the browser.
 * - No-op unless VITE_SENTRY_DSN is set (so dev/CI without a DSN stays quiet).
 * - samples at 10% in prod to stay inside the free tier; the sample rate is
 *   overridable via VITE_SENTRY_TRACES_SAMPLE_RATE for staging.
 * - beforeSend strips PII from breadcrumb data; also drops the event outright
 *   when the error is a known noisy chunk-load failure from a stale tab.
 */
export function initSentry(): void {
  if (initialized || !SENTRY_DSN) {
    return;
  }

  Sentry.init({
    dsn: SENTRY_DSN,
    environment: import.meta.env.MODE,
    release: import.meta.env.VITE_APP_VERSION,
    integrations: [Sentry.browserTracingIntegration()],
    tracesSampleRate: Number(
      import.meta.env.VITE_SENTRY_TRACES_SAMPLE_RATE ?? 0.1,
    ),
    beforeSend(event) {
      if (event.breadcrumbs) {
        event.breadcrumbs = event.breadcrumbs.map((b) => ({
          ...b,
          data: b.data
            ? Object.fromEntries(
                Object.entries(b.data).filter(
                  ([k]) =>
                    !['password', 'token', 'email', 'authorization'].includes(
                      k.toLowerCase(),
                    ),
                ),
              )
            : b.data,
        }));
      }
      return event;
    },
    ignoreErrors: [
      'Loading chunk',
      'Failed to fetch dynamically imported module',
    ],
  });

  initialized = true;
}

export { Sentry };