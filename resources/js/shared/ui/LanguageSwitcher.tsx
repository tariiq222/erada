import React from 'react';
import { useLocale } from '@shared/contexts/LocaleContext';
import {IconLanguage} from '@tabler/icons-react';

const LanguageSwitcher: React.FC = () => {
  const { locale, setLocale } = useLocale();

  const toggleLocale = () => {
    setLocale(locale === 'ar' ? 'en' : 'ar');
  };

  return (
    <button
      onClick={toggleLocale}
      className="flex items-center gap-1 p-2 sm:p-2 rounded-lg sm:rounded-xl hover:bg-[var(--surface-muted)] text-[var(--text-secondary)] hover:text-[var(--text-primary)] transition-colors duration-200"
      aria-label={locale === 'ar' ? 'Switch to English' : 'التبديل للعربية'}
      title={locale === 'ar' ? 'Switch to English' : 'التبديل للعربية'}
    >
      <IconLanguage className="h-4 w-4 sm:h-5 sm:w-5" />
      <span className="text-xs sm:text-sm font-semibold">
        {locale === 'ar' ? 'EN' : 'ع'}
      </span>
    </button>
  );
};

export default LanguageSwitcher;
