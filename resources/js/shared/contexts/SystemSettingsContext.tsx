/* global sessionStorage */
import React, { createContext, useContext, useState, useEffect, useCallback, useMemo, useRef } from 'react';
import i18n from '@shared/config/i18n';
import { systemSettingsApi } from '@shared/api/settings';
import { SystemSettings as SystemSettingsType } from '@shared/types';

interface SystemSettings {
  id?: number;
  name: string;
  name_en: string;
  code: string;
  phone: string;
  email: string;
  website: string;
}

interface ApiError {
  message?: string;
  status?: number;
}

interface SystemSettingsContextType {
  settings: SystemSettings | null;
  isLoading: boolean;
  error: string | null;
  refreshSettings: () => Promise<void>;
}

const defaultSettings: SystemSettings = {
  name: 'منصة إرادة',
  name_en: 'Erada System',
  code: 'IRADA',
  phone: '',
  email: '',
  website: '',
};

const SystemSettingsContext = createContext<SystemSettingsContextType | undefined>(undefined);

// مفتاح للتخزين المؤقت في sessionStorage
const SETTINGS_CACHE_KEY = 'system_settings_cache';
const SETTINGS_CACHE_TTL = 5 * 60 * 1000; // 5 دقائق

// قراءة الإعدادات من الـ cache
function getCachedSettings(): SystemSettings | null {
  try {
    const cached = sessionStorage.getItem(SETTINGS_CACHE_KEY);
    if (cached) {
      const { settings, timestamp } = JSON.parse(cached);
      if (Date.now() - timestamp < SETTINGS_CACHE_TTL) {
        return settings;
      }
    }
  } catch {
    // تجاهل أخطاء القراءة
  }
  return null;
}

// حفظ الإعدادات في الـ cache
function setCachedSettings(settings: SystemSettings): void {
  try {
    sessionStorage.setItem(SETTINGS_CACHE_KEY, JSON.stringify({
      settings,
      timestamp: Date.now(),
    }));
  } catch {
    // تجاهل أخطاء الكتابة
  }
}

export function SystemSettingsProvider({ children }: { children: React.ReactNode }) {
  // قراءة من الـ cache مرة واحدة فقط عند التهيئة
  const initialCachedSettings = useRef(getCachedSettings());
  const hasCachedData = initialCachedSettings.current !== null;

  const [settings, setSettings] = useState<SystemSettings | null>(initialCachedSettings.current);
  const [isLoading, setIsLoading] = useState(!hasCachedData);
  const [error, setError] = useState<string | null>(null);

  // منع التكرار اللانهائي
  const isLoadingRef = useRef(false);
  const hasLoadedRef = useRef(hasCachedData);

  // تحديث عنوان الصفحة
  const updateDocumentMeta = useCallback((sysSettings: SystemSettings) => {
    document.title = sysSettings.name || defaultSettings.name;
  }, []);

  const loadSettings = useCallback(async (force = false) => {
    // منع الاستدعاءات المتزامنة أو المتكررة
    if (isLoadingRef.current) {
      return;
    }

    // إذا تم التحميل مسبقاً ولا نريد إعادة التحميل
    if (hasLoadedRef.current && !force) {
      setIsLoading(false);
      return;
    }

    // التحقق من الـ cache أولاً (ما لم يكن force)
    if (!force) {
      const cached = getCachedSettings();
      if (cached) {
        setSettings(cached);
        updateDocumentMeta(cached);
        setIsLoading(false);
        hasLoadedRef.current = true;
        return;
      }
    }

    isLoadingRef.current = true;

    try {
      setError(null);
      const response = await systemSettingsApi.get() as SystemSettingsType;
      if (response) {
        const newSettings = {
          id: response.id,
          name: response.name || defaultSettings.name,
          name_en: response.name_en || defaultSettings.name_en,
          code: response.code || defaultSettings.code,
          phone: response.phone || '',
          email: response.email || '',
          website: response.website || '',
        };
        setSettings(newSettings);
        updateDocumentMeta(newSettings);
        setCachedSettings(newSettings); // حفظ في الـ cache
      } else {
        setSettings(defaultSettings);
        updateDocumentMeta(defaultSettings);
      }
      hasLoadedRef.current = true;
    } catch (err: unknown) {
      // في حالة الخطأ، استخدم القيم الافتراضية ولا تعيد المحاولة
      setSettings(defaultSettings);
      updateDocumentMeta(defaultSettings);
      hasLoadedRef.current = true; // نعتبره محمّل لمنع إعادة المحاولة

      const apiError = err as ApiError;
      // لا نعرض خطأ 401/429 للمستخدم - نستخدم القيم الافتراضية بصمت
      if (apiError.status !== 401 && apiError.status !== 429) {
        setError(apiError.message || i18n.t('common.failed_to_load_settings'));
      }
    } finally {
      setIsLoading(false);
      isLoadingRef.current = false;
    }
  }, [updateDocumentMeta]);

  // تحميل الإعدادات مرة واحدة فقط
  useEffect(() => {
    // إذا كان لدينا cache، فقط نحدث document title
    if (settings && hasLoadedRef.current) {
      updateDocumentMeta(settings);
      return;
    }
    loadSettings();
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const refreshSettings = useCallback(async () => {
    setIsLoading(true);
    await loadSettings(true); // force reload
  }, [loadSettings]);

  const value = useMemo<SystemSettingsContextType>(
    () => ({
      settings: settings || defaultSettings,
      isLoading,
      error,
      refreshSettings,
    }),
    [settings, isLoading, error, refreshSettings],
  );

  return (
    <SystemSettingsContext.Provider value={value}>
      {children}
    </SystemSettingsContext.Provider>
  );
}

export function useSystemSettings() {
  const context = useContext(SystemSettingsContext);
  if (context === undefined) {
    throw new Error('useSystemSettings must be used within a SystemSettingsProvider');
  }
  return context;
}

// للتوافق مع الكود القديم
export const OrganizationProvider = SystemSettingsProvider;
export const useOrganization = useSystemSettings;
