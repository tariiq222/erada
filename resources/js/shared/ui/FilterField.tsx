import * as React from 'react';
import { cn } from '@shared/lib/utils';

export interface FilterFieldProps {
  /** نص التسمية بجانب الحقل */
  label: string;
  /** الحقل (Select / Input ... إلخ) */
  children: React.ReactNode;
  /** يتمدد لملء المساحة المتاحة (افتراضي) أو يأخذ عرضه الطبيعي */
  grow?: boolean;
  className?: string;
}

/**
 * حقل فلترة موحّد: التسمية بجانب الحقل في نفس الصف (inline).
 * يُستخدم داخل FilterRow لضمان شكل موحّد لكل شرائط الفلاتر.
 */
export const FilterField: React.FC<FilterFieldProps> = ({
  label,
  children,
  grow = true,
  className,
}) => (
  <label className={cn('flex items-center gap-2', grow ? 'flex-1' : 'shrink-0', className)}>
    <span className="shrink-0 text-sm text-[var(--text-secondary)]">{label}</span>
    {children}
  </label>
);

export default FilterField;
