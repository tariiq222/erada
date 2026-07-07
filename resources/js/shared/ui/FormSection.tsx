import * as React from 'react';
import { cn } from '@shared/lib/utils';

export interface FormSectionProps {
  title: string;
  description?: string;
  columns?: 1 | 2 | 3 | 4 | 5;
  children: React.ReactNode;
  className?: string;
}

const COLUMNS_CLASS: Record<1 | 2 | 3 | 4 | 5, string> = {
  1: 'grid-cols-1',
  2: 'grid-cols-1 sm:grid-cols-2',
  3: 'grid-cols-1 sm:grid-cols-2 lg:grid-cols-3',
  4: 'grid-cols-1 sm:grid-cols-2 lg:grid-cols-4',
  5: 'grid-cols-1 sm:grid-cols-2 lg:grid-cols-5',
};

/**
 * قسم فورم موحّد: عنوان + وصف اختياري + شبكة حقول.
 * استخدم <div className="sm:col-span-2 lg:col-span-full"> لأي حقل يحتاج العرض الكامل.
 */
export const FormSection: React.FC<FormSectionProps> = ({
  title,
  description,
  columns = 1,
  children,
  className,
}) => (
  <section className={cn('space-y-4', className)}>
    <div>
      <h2 className="text-sm font-semibold text-[var(--text-primary)]">{title}</h2>
      {description && (
        <p className="mt-1 text-xs leading-relaxed text-[var(--text-tertiary)]">{description}</p>
      )}
    </div>
    <div className={cn('grid gap-x-5 gap-y-5', COLUMNS_CLASS[columns])}>
      {children}
    </div>
  </section>
);

export default FormSection;
