/**
 * أنواع النماذج - بيانات الفورمات
 */

import type { Priority, ProjectStatus, TaskStatus, TaskType } from './index';

// ============================================
// نموذج المشروع
// ============================================

/**
 * بيانات نموذج المشروع
 */
export interface ProjectFormData {
  // البيانات الأساسية
  name: string;
  name_en: string;
  code: string;
  description: string;

  // الحالة والأولوية
  status: ProjectStatus;
  priority: Priority;

  // التواريخ
  start_date: string;
  end_date: string;

  // الفريق
  department_id: number | null;
  manager_id: number | null;
  sponsor_id: number | null;

  // البرنامج
  program_id: number | null;

  // الميزانية
  budget: string;

  // النطاق
  objectives: string[];
  in_scope: string[];
  out_of_scope: string[];

  // البيانات المرتبطة
  milestones: MilestoneFormData[];
  tasks: TaskFormData[];
  risks: RiskFormData[];
  stakeholders: StakeholderFormData[];
  team_members: TeamMemberFormData[];
}

/**
 * القيم الافتراضية لنموذج المشروع
 */
export const defaultProjectFormData: ProjectFormData = {
  name: '',
  name_en: '',
  code: '',
  description: '',
  status: 'draft',
  priority: 'medium',
  start_date: '',
  end_date: '',
  department_id: null,
  manager_id: null,
  sponsor_id: null,
  program_id: null,
  budget: '',
  objectives: [],
  in_scope: [],
  out_of_scope: [],
  milestones: [],
  tasks: [],
  risks: [],
  stakeholders: [],
  team_members: [],
};

// ============================================
// نموذج المهمة
// ============================================

/**
 * بيانات نموذج المهمة
 */
export interface TaskFormData {
  title: string;
  description: string;
  project_id: number | null;
  milestone_id: number | null;
  milestone_index?: number;
  assigned_to: number | null;
  status: TaskStatus;
  priority: Priority;
  task_type: TaskType;
  start_date: string;
  due_date: string;
  estimated_hours: number | null;
  parent_id: number | null;
  is_private: boolean;
  department_id: number | null;
  subtasks: SubtaskFormData[];
}

/**
 * القيم الافتراضية لنموذج المهمة
 */
export const defaultTaskFormData: TaskFormData = {
  title: '',
  description: '',
  project_id: null,
  milestone_id: null,
  assigned_to: null,
  status: 'todo',
  priority: 'medium',
  task_type: 'personal',
  start_date: '',
  due_date: '',
  estimated_hours: null,
  parent_id: null,
  is_private: false,
  department_id: null,
  subtasks: [],
};

/**
 * بيانات المهمة الفرعية
 */
export interface SubtaskFormData {
  title: string;
  status: TaskStatus;
  assigned_to: number | null;
}

// ============================================
// نموذج المرحلة
// ============================================

/**
 * بيانات نموذج المرحلة
 */
export interface MilestoneFormData {
  name: string;
  description: string;
  start_date: string;
  due_date: string;
  status: string;
  deliverables: DeliverableFormData[];
}

/**
 * القيم الافتراضية لنموذج المرحلة
 */
export const defaultMilestoneFormData: MilestoneFormData = {
  name: '',
  description: '',
  start_date: '',
  due_date: '',
  status: 'pending',
  deliverables: [],
};

/**
 * بيانات المخرج
 */
export interface DeliverableFormData {
  name: string;
  description: string;
  status: string;
}

// ============================================
// نموذج المخاطرة
// ============================================

/**
 * بيانات نموذج المخاطرة
 */
export interface RiskFormData {
  description: string;
  probability: 'low' | 'medium' | 'high';
  impact: 'low' | 'medium' | 'high';
  mitigation: string;
  status: string;
}

/**
 * القيم الافتراضية لنموذج المخاطرة
 */
export const defaultRiskFormData: RiskFormData = {
  description: '',
  probability: 'medium',
  impact: 'medium',
  mitigation: '',
  status: 'open',
};

// ============================================
// نموذج صاحب المصلحة
// ============================================

/**
 * بيانات نموذج صاحب المصلحة
 */
export interface StakeholderFormData {
  name: string;
  role: string;
  user_id: number | null;
  email: string;
  influence: 'low' | 'medium' | 'high';
}

/**
 * القيم الافتراضية لنموذج صاحب المصلحة
 */
export const defaultStakeholderFormData: StakeholderFormData = {
  name: '',
  role: 'other',
  user_id: null,
  email: '',
  influence: 'medium',
};

// ============================================
// نموذج عضو الفريق
// ============================================

/**
 * بيانات نموذج عضو الفريق
 */
export interface TeamMemberFormData {
  user_id: number;
  role: string;
}

// ============================================
// أنواع مساعدة للنماذج
// ============================================

/**
 * حالة النموذج
 */
export interface FormState<T> {
  data: T;
  errors: Record<string, string>;
  isDirty: boolean;
  isSubmitting: boolean;
  isValid: boolean;
}

/**
 * قواعد التحقق
 */
export interface ValidationRule {
  required?: boolean;
  min?: number;
  max?: number;
  minLength?: number;
  maxLength?: number;
  pattern?: RegExp;
  custom?: (value: unknown) => string | null;
}

export type ValidationRules<T> = {
  [K in keyof T]?: ValidationRule;
};

/**
 * أخطاء التحقق للنموذج
 */
export type FormValidationErrors<T> = {
  [K in keyof T]?: string;
};
