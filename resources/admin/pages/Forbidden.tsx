import { Link } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { IconLockAccess } from '@tabler/icons-react';

export function Forbidden() {
  const { t } = useTranslation();

  return (
    <main className="flex min-h-screen items-center justify-center bg-[var(--surface-subtle)] p-6" dir="rtl">
      <section className="max-w-md text-center">
        <IconLockAccess className="mx-auto h-12 w-12 text-[var(--status-danger)]" aria-hidden="true" />
        <h1 className="mt-5 text-2xl font-bold text-[var(--text-primary)]">{t('ovr.api.access_denied')}</h1>
        <p className="mt-3 text-sm leading-6 text-[var(--text-secondary)]">
          {t('admin.shell.brand')}
        </p>
        <Link className="mt-6 inline-flex rounded-lg bg-[var(--accent-default)] px-4 py-2 text-sm font-semibold text-[var(--text-inverse)]" to="/login">
          {t('auth.login')}
        </Link>
      </section>
    </main>
  );
}
