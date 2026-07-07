export interface MilestoneOption {
  id: number;
  name: string;
  status: string;
  start_date?: string;
  due_date?: string;
}

export interface ProjectOption {
  id: number;
  name: string;
  code: string;
  start_date?: string;
  end_date?: string;
  milestones?: MilestoneOption[];
}

export interface UserOption {
  id: number;
  name: string;
  email?: string;
  phone?: string;
}

export interface NewUserFormData {
  email: string;
}

export interface TaskOption {
  id: number;
  title: string;
}

export type TaskType = 'project' | 'personal' | 'department' | 'recurring';

export interface TaskFormData {
  // نوع المهمة
  type: TaskType;
  // حقول مشتركة
  project_id: string;
  milestone_id: string;
  parent_id: string;
  assigned_to: string;
  title: string;
  description: string;
  status: string;
  priority: string;
  start_date: string;
  due_date: string;
  estimated_hours: string;
  // حقول المهام الموحدة
  owner_id: string;
  department_id: string;
  is_private: boolean;
  recurrence_rule: string;
}

export interface DepartmentOption {
  id: number;
  name: string;
  level?: number;
}

export interface MilestoneFormData {
  name: string;
  description: string;
  duration_value: string;
  duration_unit: 'day' | 'week' | 'month';
}

export interface ValidationErrors {
  [key: string]: string[];
}

export interface DateConstraints {
  minDate: string;
  maxDate: string;
  constraintType: 'milestone' | 'project';
  constraintName: string;
}
