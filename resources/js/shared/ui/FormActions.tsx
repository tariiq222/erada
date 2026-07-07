import * as React from 'react';
import { cn } from '@shared/lib/utils';

export interface FormActionsProps {
  children: React.ReactNode;
  className?: string;
}

/**
 * شريط إجراءات الفورم (حفظ/إلغاء). يفصله خط علوي، ويتكدّس عمودياً على الموبايل.
 */
export const FormActions: React.FC<FormActionsProps> = ({ children, className }) => (
  <div
    className={cn(
      'flex flex-col-reverse gap-2 border-t border-[var(--border-default)] pt-5',
      'sm:flex-row sm:items-center sm:justify-end sm:gap-3',
      className
    )}
  >
    {children}
  </div>
);

export default FormActions;
