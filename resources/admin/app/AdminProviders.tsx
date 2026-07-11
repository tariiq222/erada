import type { ReactNode } from 'react';
import { AuthProvider } from '@shared/contexts/AuthContext';
import { LocaleProvider } from '@shared/contexts/LocaleContext';
import { SystemSettingsProvider } from '@shared/contexts/SystemSettingsContext';
import { ThemeProvider } from '@shared/contexts/ThemeContext';
import { ToastProvider } from '@shared/ui/Toast';

export function AdminProviders({ children }: { children: ReactNode }) {
  return (
    <ThemeProvider>
      <AuthProvider>
        <SystemSettingsProvider>
          <LocaleProvider>
            <ToastProvider>{children}</ToastProvider>
          </LocaleProvider>
        </SystemSettingsProvider>
      </AuthProvider>
    </ThemeProvider>
  );
}
