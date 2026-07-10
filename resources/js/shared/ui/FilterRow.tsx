import * as React from 'react';
import { useTranslation } from 'react-i18next';
import { cn } from '@shared/lib/utils';
// Phase 4C — direct leaf imports (not via @shared/ui barrel). The
// barrel re-exports FilterRow + Button/Card/CardContent; pulling
// siblings through the barrel would route this leaf's chunk through
// the same chunk as the barrel, creating a circular chunk shape.
// The stable direct imports keep the chunk graph acyclic.
import { Button } from './Button';
import { Card, CardContent } from './Card';

export interface FilterRowProps {
  /** حقول الفلترة، يفضّل لفّها بـ FilterField */
  children: React.ReactNode;
  /** دالة مسح الفلاتر — يظهر الزر عند تمريرها */
  onClear?: () => void;
  /** نص زر المسح (افتراضي: مسح الفلاتر) */
  clearLabel?: string;
  className?: string;
}

/**
 * شريط فلاتر موحّد: صف واحد ممتد، ارتفاع مضغوط، وزر مسح يُدفع للطرف المقابل.
 * كل الصفحات تستخدمه لضمان شكل موحّد.
 */
export const FilterRow: React.FC<FilterRowProps> = ({
  children,
  onClear,
  clearLabel,
  className,
}) => {
  const { t } = useTranslation();
  return (
    <Card>
      <CardContent
        className={cn('flex flex-wrap items-center gap-3 px-4 py-2.5', className)}
      >
        {children}
        {onClear && (
          <Button
            variant="ghost"
            size="sm"
            onClick={onClear}
            className="ms-auto shrink-0"
          >
            {clearLabel ?? t('common.clear_filters', { defaultValue: 'مسح الفلاتر' })}
          </Button>
        )}
      </CardContent>
    </Card>
  );
};

export default FilterRow;
