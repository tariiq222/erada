import { useTheme } from '@shared/contexts/ThemeContext';
import {IconSun, IconMoon} from '@tabler/icons-react';

export default function ThemeSwitcher() {
  const { resolvedTheme, toggleTheme } = useTheme();

  return (
    <button
      onClick={toggleTheme}
      className="flex items-center p-2 sm:p-2 rounded-lg sm:rounded-xl hover:bg-[var(--surface-muted)] text-[var(--text-tertiary)] hover:text-[var(--text-secondary)] transition-colors duration-200"
      aria-label={resolvedTheme === 'light' ? 'الوضع الداكن' : 'الوضع الفاتح'}
      title={resolvedTheme === 'light' ? 'الوضع الداكن' : 'الوضع الفاتح'}
    >
      {resolvedTheme === 'light' ? (
        <IconMoon className="h-4 w-4 sm:h-5 sm:w-5" />
      ) : (
        <IconSun className="h-4 w-4 sm:h-5 sm:w-5" />
      )}
    </button>
  );
}
