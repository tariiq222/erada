/**
 * أنواع API - الطلبات والاستجابات
 */

// ============================================
// الاستجابات العامة
// ============================================

/**
 * استجابة API الأساسية
 */
export interface ApiResponse<T = unknown> {
  data: T;
  message?: string;
  success?: boolean;
  errors?: Record<string, string[]>;
  download_url?: string;
  meta?: Record<string, unknown>;
  links?: Record<string, string | null>;
  version_hash?: string;
}

/**
 * استجابة مع التصفح (بتنسيق Laravel Resource)
 */
export interface ApiPaginatedResponse<T> {
  success?: boolean;
  data: T[];
  message?: string;
  meta: {
    current_page: number;
    from: number | null;
    last_page: number;
    per_page: number;
    to: number | null;
    total: number;
  };
  links: {
    first: string | null;
    last: string | null;
    prev: string | null;
    next: string | null;
  };
}

/**
 * خطأ API
 */
export interface ApiError {
  message: string;
  errors?: Record<string, string[]>;
  status: number;
  code?: string;
  success?: false;
  retry_after?: number;
}

export interface ApiDownloadMetadata {
  id?: number | string;
  name?: string;
  filename?: string;
  file_type?: string;
  mime_type?: string;
  size?: number;
  url?: string;
  download_url: string;
}

export interface ApiUploadResponse<TMetadata = ApiDownloadMetadata> extends ApiResponse<TMetadata> {
  url?: string;
  path?: string;
  download_url?: string;
}

export interface ApiVersionedResponse<T = unknown> extends ApiResponse<T> {
  version_hash?: string;
}

export interface PublicSurveyVersionHash {
  version_hash: string;
}

// ============================================
// طلبات المشاريع
// ============================================

/**
 * طلب إنشاء مشروع
 */
export interface CreateProjectRequest {
  name: string;
  name_en?: string;
  code?: string;
  description?: string;
  status?: string;
  priority?: string;
  department_id?: number;
  manager_id?: number;
  sponsor_id?: number;
  program_id?: number;
  start_date?: string;
  end_date?: string;
  budget?: number;
  objectives?: string[];
  in_scope?: string[];
  out_of_scope?: string[];
  milestones?: CreateMilestoneRequest[];
  tasks?: CreateTaskRequest[];
  risks?: CreateRiskRequest[];
  stakeholders?: CreateStakeholderRequest[];
  team_members?: CreateTeamMemberRequest[];
}

/**
 * طلب تحديث مشروع
 */
export interface UpdateProjectRequest extends Partial<CreateProjectRequest> {
  id?: number;
}

/**
 * طلب تصفية المشاريع
 */
export interface ProjectFilters {
  status?: string;
  priority?: string;
  department_id?: number;
  manager_id?: number;
  search?: string;
  date_from?: string;
  date_to?: string;
}

// ============================================
// طلبات المهام
// ============================================

/**
 * طلب إنشاء مهمة
 */
export interface CreateTaskRequest {
  title: string;
  name?: string; // بديل لـ title
  description?: string;
  project_id?: number;
  milestone_id?: number;
  assigned_to?: number;
  priority?: string;
  status?: string;
  start_date?: string;
  due_date?: string;
  estimated_hours?: number;
  parent_id?: number;
  task_type?: string;
  milestone_index?: number; // للربط بالمرحلة عند الإنشاء
}

/**
 * طلب تحديث مهمة
 */
export interface UpdateTaskRequest extends Partial<CreateTaskRequest> {
  id?: number;
  progress?: number;
  actual_hours?: number;
  completed_date?: string;
}

/**
 * طلب تصفية المهام
 */
export interface TaskFilters {
  status?: string;
  priority?: string;
  project_id?: number;
  assigned_to?: number;
  task_type?: string;
  search?: string;
  due_date_from?: string;
  due_date_to?: string;
  is_overdue?: boolean;
}

// ============================================
// طلبات المراحل
// ============================================

/**
 * طلب إنشاء مرحلة
 */
export interface CreateMilestoneRequest {
  name: string;
  description?: string;
  start_date: string;
  due_date: string;
  status?: string;
  deliverables?: CreateDeliverableRequest[];
}

/**
 * طلب إنشاء مخرج
 */
export interface CreateDeliverableRequest {
  name: string;
  description?: string;
  status?: string;
}

// ============================================
// طلبات المخاطر
// ============================================

/**
 * طلب إنشاء مخاطرة
 */
export interface CreateRiskRequest {
  description: string;
  risk?: string; // بديل لـ description
  probability?: 'low' | 'medium' | 'high';
  impact?: 'low' | 'medium' | 'high';
  mitigation?: string;
  response?: string; // بديل لـ mitigation
  status?: string;
}

// ============================================
// طلبات أصحاب المصلحة
// ============================================

/**
 * طلب إنشاء صاحب مصلحة
 */
export interface CreateStakeholderRequest {
  name: string;
  role: string;
  user_id?: number;
  email?: string;
  contact?: string; // بديل لـ email
  influence?: 'low' | 'medium' | 'high';
}

// ============================================
// طلبات فريق العمل
// ============================================

/**
 * طلب إضافة عضو للفريق
 */
export interface CreateTeamMemberRequest {
  user_id: number;
  role?: string;
}

// ============================================
// طلبات المستخدمين
// ============================================

/**
 * طلب تصفية المستخدمين
 */
export interface UserFilters {
  department_id?: number;
  role?: string;
  is_active?: boolean;
  search?: string;
}

// ============================================
// إحصائيات
// ============================================

/**
 * إحصائيات المشاريع
 */
export interface ProjectStatistics {
  total: number;
  total_budget: number;
  average_progress: number;
  by_status: Record<string, number>;
  by_priority?: Record<string, number>;
  overdue_count?: number;
}

/**
 * إحصائيات المهام
 */
export interface TaskStatistics {
  total: number;
  completed: number;
  in_progress: number;
  overdue: number;
  by_status: Record<string, number>;
  by_priority?: Record<string, number>;
}

/**
 * إحصائيات لوحة التحكم
 */
export interface DashboardStatistics {
  projects: ProjectStatistics;
  tasks: TaskStatistics;
  recent_activities?: unknown[];
}
