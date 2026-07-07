/**
 * Per-record abilities payload returned by every element endpoint
 * (ProjectResource, TaskResource, RiskResource, IncidentReportResource,
 * DepartmentController::show). Computed server-side by ElementAbilities
 * via AccessDecision::can. The frontend MUST use this for "can I edit
 * *this* record" rather than inferring from auth/me.permissions.
 */
export interface Abilities {
  view?: boolean;
  edit?: boolean;
  delete?: boolean;
  manage_members?: boolean;
  change_status?: boolean;
  close?: boolean;
  complete?: boolean;
  assign?: boolean;
  investigate?: boolean;
  reassess?: boolean;
  assign_roles?: boolean;
}

export interface User {
  id: number;
  name: string;
  email: string;
  department_id: number | null;
  phone: string | null;
  extension: string | null;
  job_title: string | null;
  is_active: boolean;
  preferred_locale?: string | null;
  roles: string[];
  /**
   * Canonical dotted capabilities (`projects.view`, `meetings.record_decisions`, ...).
   * The single vocabulary maintained by the AuthZ engine. Use this for read-side
   * displays (e.g. a "Permissions" tab) and as the source of truth going forward.
   * `permissions[]` below is the legacy flat-key fallback that the access bridge
   * consults only while `user.access` is stale; it is being phased out per
   * docs/authz/deprecation-policy.md.
   */
  capabilities?: string[];
  /**
   * Legacy flat permission names (`view_projects`, `edit_users`, ...).
   * Retained as a compatibility layer — DO NOT add new reads here; use
   * `capabilities[]` for display or `useCan('module.action')` for gating.
   */
  permissions?: string[];
  /**
   * Structured access map derived from canonical dotted capabilities
   * (e.g. `tasks.assign`). Read `user.access[module][action] === true`
   * to decide if the current user has a module-level capability. For
   * per-record decisions use the resource's `abilities` payload, not
   * this map. See `useCan` / `useAccess` in `@shared/api/access`.
   */
  access?: AccessMap;
  department?: Department;
}

/**
 * Map of canonical dotted capabilities, projected to a nested object
 * with each leaf value set to `true`. Shape:
 *   { projects: { view: true, create: true }, tasks: { assign: true } }
 */
export type AccessMap = Record<string, Record<string, true>>;

// إعدادات النظام (واحد فقط في النظام)
export interface SystemSettings {
  id: number;
  name: string;
  name_en: string | null;
  code: string | null;
  logo: string | null;
  region: string | null;
  city: string | null;
  address: string | null;
  phone: string | null;
  email: string | null;
  website: string | null;
  settings: Record<string, unknown> | null;
}

// مستويات الأقسام
export type DepartmentLevel = 1 | 2 | 3 | 4 | 5 | 6;

export const DEPARTMENT_LEVELS: Record<DepartmentLevel, string> = {
  1: 'الإدارة العليا',
  2: 'إدارة تنفيذية',
  3: 'إدارة',
  4: 'قسم',
  5: 'وحدة',
  6: 'شعبة',
};

// القسم
export interface Department {
  id: number;
  name: string;
  email: string | null;
  code: string | null;
  description: string | null;
  parent_id: number | null;
  level: DepartmentLevel;
  manager_id: number | null;
  is_active: boolean;
  // العلاقات
  parent?: Department | null;
  manager?: User | null;
  children?: Department[];
  users_count?: number;
  full_path?: string;
}

export interface Project {
  id: number;
  code: string;
  name: string;
  department_id: number | null;
  manager_id: number | null;
  created_by: number | null;
  description: string | null;
  objectives: string[] | null;
  in_scope: string[] | null;
  out_of_scope: string[] | null;
  status: ProjectStatus;
  priority: Priority;
  start_date: string | null;
  end_date: string | null;
  actual_start_date: string | null;
  actual_end_date: string | null;
  budget: string | null;
  actual_cost: string | null;
  progress: number;
  human_resources: string | null;
  technical_resources: string | null;
  financial_resources: string | null;
  // Per-record abilities; see Abilities. Treat as authoritative for record-level
  // gating. Use hasPermission(user.permissions) for menu/button gating instead.
  abilities?: Abilities;
  // العلاقات
  department?: Department;
  manager?: User;
  creator?: User;
  members?: User[];
  milestones?: Milestone[];
  tasks?: Task[];
}

export interface Task {
  id: number;
  // نوع المهمة (موديول المهام الموحد)
  type?: TaskType;
  project_id: number | null;
  milestone_id: number | null;
  parent_id: number | null;
  assigned_to: number | null;
  created_by: number | null;
  // حقول المهام الموحدة
  owner_id?: number | null;
  department_id?: number | null;
  is_private?: boolean;
  recurrence_rule?: string | null;
  recurring_parent_id?: number | null;
  // Per-record abilities; see Abilities.
  abilities?: Abilities;
  // الحقول الأساسية
  title: string;
  description: string | null;
  status: TaskStatus;
  priority: Priority;
  start_date: string | null;
  due_date: string | null;
  completed_date: string | null;
  progress: number;
  estimated_hours: number | null;
  actual_hours: number | null;
  // العلاقات
  assignee?: User;
  creator?: User;
  owner?: User;
  project?: Project;
  milestone?: Milestone;
  department?: Department;
  subtasks?: Task[];
}

export interface Milestone {
  id: number;
  project_id: number;
  name: string;
  description: string | null;
  start_date: string | null;
  due_date: string | null;
  actual_date: string | null;
  status: MilestoneStatus;
  progress: number;
  order: number;
  // العلاقات
  project?: Project;
  deliverables?: Deliverable[];
  tasks?: Task[];
}

export interface Deliverable {
  id: number;
  milestone_id: number;
  name: string;
  description: string | null;
  status: 'pending' | 'in_progress' | 'completed';
  progress: number;
  order: number;
}

export interface Stakeholder {
  id: number;
  project_id: number;
  name: string;
  role: string;
  organization: string | null;
  email: string | null;
  phone: string | null;
}

export interface ProjectRisk {
  id: number;
  project_id: number;
  name: string;
  description: string | null;
  probability: RiskProbability;
  impact: RiskProbability;
  mitigation: string | null;
  status: 'open' | 'mitigated' | 'closed';
  order: number;
}

export type ProjectStatus =
  | 'draft'
  | 'planning'
  | 'in_progress'
  | 'on_hold'
  | 'completed'
  | 'cancelled';

export type TaskStatus =
  | 'todo'
  | 'in_progress'
  | 'in_review'
  | 'completed'
  | 'cancelled'
  | 'on_hold';

// أنواع المهام (موديول المهام الموحد)
export type TaskType = 'project' | 'personal' | 'department' | 'recurring';

export type MilestoneStatus =
  | 'pending'
  | 'in_progress'
  | 'completed'
  | 'delayed';

export type Priority = 'low' | 'medium' | 'high' | 'urgent' | 'critical';

export type RiskProbability = 'low' | 'medium' | 'high';

export interface PageProps {
  auth: {
    user: User;
  };
}

// ترجمات الحالات
export const PROJECT_STATUS_LABELS: Record<ProjectStatus, string> = {
  draft: 'مسودة',
  planning: 'تخطيط',
  in_progress: 'قيد التنفيذ',
  on_hold: 'معلق',
  completed: 'مكتمل',
  cancelled: 'ملغى',
};

export const TASK_STATUS_LABELS: Record<TaskStatus, string> = {
  todo: 'للتنفيذ',
  in_progress: 'قيد التنفيذ',
  in_review: 'قيد المراجعة',
  completed: 'مكتمل',
  cancelled: 'ملغاة',
  on_hold: 'معلقة',
};

// ترجمات أنواع المهام
export const TASK_TYPE_LABELS: Record<TaskType, string> = {
  project: 'مهمة مشروع',
  personal: 'مهمة شخصية',
  department: 'مهمة إدارية',
  recurring: 'مهمة متكررة',
};

export const PRIORITY_LABELS: Record<Priority, string> = {
  low: 'منخفضة',
  medium: 'متوسطة',
  high: 'عالية',
  urgent: 'عاجلة',
  critical: 'حرجة',
};

// إعادة تصدير الأنواع المشتركة
export * from './common';

// إعادة تصدير أنواع API
export * from './api';

// إعادة تصدير أنواع النماذج
export * from './forms';
