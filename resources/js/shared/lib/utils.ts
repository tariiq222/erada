import { clsx, type ClassValue } from 'clsx';
import { twMerge } from 'tailwind-merge';

export function cn(...inputs: ClassValue[]) {
  return twMerge(clsx(inputs));
}

/**
 * تنسيق التاريخ بالأرقام الإنجليزية (0-9) بدلاً من الأرقام الهندية
 * @param date التاريخ
 * @param options خيارات التنسيق
 * @returns التاريخ المنسق بالأرقام الإنجليزية
 */
export function formatDate(
  date: Date | string,
  options?: Intl.DateTimeFormatOptions
): string {
  const d = typeof date === 'string' ? new Date(date) : date;
  // فرض نظام 24 ساعة عند تمرير hour/minute حتى لا يستخدم en-GB الوضع 12 ساعة تلقائياً
  const defaultOptions: Intl.DateTimeFormatOptions = {
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour12: false,
  };
  return d.toLocaleDateString('en-GB', options || defaultOptions);
}

/**
 * تنسيق التاريخ بشكل مختصر (يوم وشهر فقط)
 */
export function formatDateShort(date: Date | string): string {
  const d = typeof date === 'string' ? new Date(date) : date;
  return d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short' });
}

/**
 * تنسيق التاريخ بشكل كامل مع اسم اليوم
 */
export function formatDateFull(date: Date | string): string {
  const d = typeof date === 'string' ? new Date(date) : date;
  return d.toLocaleDateString('en-GB', {
    weekday: 'long',
    year: 'numeric',
    month: 'long',
    day: 'numeric',
  });
}

// تنسيقات موحدة — أرقام لاتينية 24 ساعة لجميع اللغات

/**
 * تنسيق التاريخ والوقت dd/MM/yyyy HH:mm بأرقام لاتينية ونظام 24 ساعة
 * @param date التاريخ
 * @param options خيارات إضافية لـ Intl.DateTimeFormat
 * @returns التاريخ والوقت المنسق
 */
export function formatDateTime(
  date: Date | string,
  options?: Intl.DateTimeFormatOptions
): string {
  const d = typeof date === 'string' ? new Date(date) : date;
  const defaultOptions: Intl.DateTimeFormatOptions = {
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    hour12: false,
    hourCycle: 'h23',
  };
  return d.toLocaleString('en-GB', options ?? defaultOptions);
}

/**
 * تنسيق الوقت HH:mm بأرقام لاتينية ونظام 24 ساعة
 * @param date التاريخ
 * @param options خيارات إضافية لـ Intl.DateTimeFormat
 * @returns الوقت المنسق
 */
export function formatTime(
  date: Date | string,
  options?: Intl.DateTimeFormatOptions
): string {
  const d = typeof date === 'string' ? new Date(date) : date;
  const defaultOptions: Intl.DateTimeFormatOptions = {
    hour: '2-digit',
    minute: '2-digit',
    hour12: false,
    hourCycle: 'h23',
  };
  return d.toLocaleTimeString('en-GB', options ?? defaultOptions);
}

/**
 * تنسيق الأرقام بأرقام لاتينية وفواصل قياسية (en-US)
 * @param value القيمة العددية
 * @param options خيارات إضافية لـ Intl.NumberFormat
 * @returns الرقم المنسق
 */
export function formatNumber(
  value: number | bigint,
  options?: Intl.NumberFormatOptions
): string {
  return new Intl.NumberFormat('en-US', options).format(value);
}

/**
 * تنسيق العملة بأرقام لاتينية وفواصل en-US
 * @param value القيمة المالية
 * @param currency رمز العملة (الافتراضي SAR)
 * @param options خيارات إضافية لـ Intl.NumberFormat
 * @returns المبلغ المنسق
 */
export function formatCurrency(
  value: number | bigint,
  currency: string = 'SAR',
  options?: Intl.NumberFormatOptions
): string {
  return new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency,
    ...options,
  }).format(value);
}

/**
 * تنسيق النسبة المئوية بأرقام لاتينية
 * @param value القيمة ككسر عشري (مثال 0.85 => "85%")
 * @param fractionDigits عدد الخانات العشرية (الافتراضي 0)
 * @returns النسبة المنسقة
 */
export function formatPercent(value: number, fractionDigits: number = 0): string {
  return new Intl.NumberFormat('en-US', {
    style: 'percent',
    minimumFractionDigits: fractionDigits,
    maximumFractionDigits: fractionDigits,
  }).format(value);
}
