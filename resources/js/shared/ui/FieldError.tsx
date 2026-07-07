import * as React from 'react';
import {IconAlertCircle} from '@tabler/icons-react';
import { cn } from '@shared/lib/utils';

export interface FieldErrorProps {
  /** id مطابق لـ aria-describedby على الحقل المرتبط. */
  id?: string;
  children?: React.ReactNode;
  className?: string;
}

/**
 * رسالة خطأ موحّدة لحقول النماذج. تُربط بالحقل عبر `id` + `aria-describedby`
 * وتُعلَن لقارئ الشاشة عبر `role="alert"`.
 */
export const FieldError: React.FC<FieldErrorProps> = ({ id, children, className }) => {
  if (!children) return null;
  return (
    <p
      id={id}
      role="alert"
      className={cn(
        'mt-1 flex items-center gap-1 text-sm text-[var(--status-danger-text)]',
        className
      )}
    >
      <IconAlertCircle className="h-4 w-4 shrink-0" aria-hidden />
      {children}
    </p>
  );
};

FieldError.displayName = 'FieldError';
