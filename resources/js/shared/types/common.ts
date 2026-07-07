/**
 * Common Types - أنواع مشتركة عامة
 */

// ============ API & Pagination ============

/**
 * استجابة مُصفّحة من الخادم
 */
export interface PaginatedResponse<T> {
  data: T[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
  from?: number;
  to?: number;
}

/**
 * معلمات الصفحة للـ API
 */
export interface PaginationParams {
  page?: number;
  per_page?: number;
  sort_by?: string;
  sort_order?: 'asc' | 'desc';
}

/**
 * أخطاء التحقق من الخادم
 */
export interface ValidationErrors {
  [key: string]: string[];
}

/**
 * استجابة خطأ من الخادم
 */
export interface ApiErrorResponse {
  message: string;
  errors?: ValidationErrors;
  status?: number;
}

// ============ UI Components ============

/**
 * خيار في قائمة منسدلة
 */
export interface SelectOption<T = string | number> {
  value: T;
  label: string;
  disabled?: boolean;
}

/**
 * عنصر في الجدول مع معرّف
 */
export interface TableItem {
  id: number | string;
  [key: string]: unknown;
}

/**
 * حالة الفلاتر الأساسية
 */
export interface BaseFilters {
  search?: string;
  page?: number;
  per_page?: number;
}

// ============ Time & Date ============

/**
 * مؤشر الوقت للمهام
 */
export interface TimeIndicator {
  days_remaining: number | null;
  days_elapsed: number | null;
  total_days: number | null;
  time_progress: number | null;
  status: 'normal' | 'warning' | 'urgent' | 'overdue' | 'completed';
  has_due_date: boolean;
}

// ============ Comments & Attachments ============

/**
 * المرفق
 */
export interface Attachment {
  id: number;
  name: string;
  file_type: string;
  size?: number;
  url: string;
}

/**
 * التعليق
 */
export interface Comment {
  id: number;
  content: string;
  user: {
    id: number;
    name: string;
  };
  mentioned_users: number[];
  attachments: Attachment[];
  created_at: string;
  updated_at: string;
}

// ============ Activity Log ============

/**
 * سجل النشاط
 */
export interface ActivityLogEntry {
  id: number;
  description: string;
  subject_type: string;
  subject_id: number;
  causer_type: string | null;
  causer_id: number | null;
  properties: Record<string, unknown>;
  created_at: string;
  causer?: {
    id: number;
    name: string;
  };
}

// ============ Utility Types ============

/**
 * جعل جميع الحقول اختيارية بشكل متداخل
 */
export type DeepPartial<T> = {
  [P in keyof T]?: T[P] extends object ? DeepPartial<T[P]> : T[P];
};

/**
 * استخراج مفاتيح معينة من نوع
 */
export type PickByValue<T, V> = {
  [K in keyof T as T[K] extends V ? K : never]: T[K];
};

/**
 * نوع مع معرّف مطلوب
 */
export type WithId<T> = T & { id: number };

/**
 * نوع بدون معرّف
 */
export type WithoutId<T> = Omit<T, 'id'>;
