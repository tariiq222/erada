import { Link } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { IconMapOff } from '@tabler/icons-react';

export function NotFound() {
  const { t } = useTranslation();

  return (
    <section className="mx-auto flex min-h-[60vh] max-w-md flex-col items-center justify-center p-6 text-center" dir="rtl">
      <IconMapOff className="h-12 w-12 text-[var(--text-tertiary)]" aria-hidden="true" />
      <h1 className="mt-5 text-2xl font-bold text-[var(--text-primary)]">{t('ovr.not_found')}</h1>
      <p className="mt-3 text-sm text-[var(--text-secondary)]">{t('errors.route')}</p>
      <Link className="mt-6 text-sm font-semibold text-[var(--accent-default)]" to="/overview">
        {t('admin.overview.title')}
      </Link>
    </section>
  );
}
