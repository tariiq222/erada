import i18n from 'i18next';
import { initReactI18next } from 'react-i18next';
import ar from '../../../../lang/ar.json';
import en from '../../../../lang/en.json';

const locale = document.documentElement.lang || 'ar';

i18n
  .use(initReactI18next)
  .init({
    lng: locale,
    fallbackLng: 'ar',
    supportedLngs: ['ar', 'en'],

    // مفاتيح الترجمة flat بنقاط (مثل "nav.dashboard") — أوقف التفسير كتداخل
    keySeparator: false,
    nsSeparator: false,

    resources: {
      ar: { translation: ar },
      en: { translation: en },
    },

    interpolation: {
      escapeValue: false,
    },

    react: {
      useSuspense: false,
    },
  });

export default i18n;
