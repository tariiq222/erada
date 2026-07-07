import React, { createContext, useContext, useState, useCallback, useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import { api } from '@shared/api/client';

type Locale = 'ar' | 'en';
type Direction = 'rtl' | 'ltr';

interface LocaleContextType {
  locale: Locale;
  direction: Direction;
  setLocale: (locale: Locale) => Promise<void>;
}

const LocaleContext = createContext<LocaleContextType | undefined>(undefined);

const RTL_LOCALES: Locale[] = ['ar'];

function getDirection(locale: Locale): Direction {
  return RTL_LOCALES.includes(locale) ? 'rtl' : 'ltr';
}

export function LocaleProvider({ children }: { children: React.ReactNode }) {
  const { i18n } = useTranslation();
  const initialLocale = (document.documentElement.lang || 'ar') as Locale;

  const [locale, setLocaleState] = useState<Locale>(initialLocale);
  const [direction, setDirection] = useState<Direction>(getDirection(initialLocale));

  const setLocale = useCallback(async (newLocale: Locale) => {
    await i18n.changeLanguage(newLocale);

    const newDirection = getDirection(newLocale);
    document.documentElement.lang = newLocale;
    document.documentElement.dir = newDirection;

    setLocaleState(newLocale);
    setDirection(newDirection);
    localStorage.setItem('preferred_locale', newLocale);

    // حفظ اللغة في الخادم فقط إذا كان المستخدم مسجل دخول
    if (api.isUserAuthenticated()) {
      try {
        await api.put('/user/locale', { locale: newLocale });
      } catch {
        // تجاهل الخطأ - اللغة محفوظة محلياً في localStorage
      }
    }
  }, [i18n]);

  const value = useMemo<LocaleContextType>(
    () => ({ locale, direction, setLocale }),
    [locale, direction, setLocale],
  );

  return (
    <LocaleContext.Provider value={value}>
      {children}
    </LocaleContext.Provider>
  );
}

export function useLocale() {
  const context = useContext(LocaleContext);
  if (context === undefined) {
    throw new Error('useLocale must be used within a LocaleProvider');
  }
  return context;
}
