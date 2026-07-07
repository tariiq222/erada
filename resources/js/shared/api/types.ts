/**
 * API Types - أنواع البيانات للـ API
 */

import type { TaskStatus, TaskType, Priority, ProjectStatus, User } from '@shared/types';

// ============ API Response Types ============

// Auth
export interface LoginResponse {
  user: User | { id: number; name: string };
  token?: string;
  // 2FA fields (when 2FA is enabled)
  requires_2fa?: boolean;
  pending_token?: string;
}

export interface UserResponse {
  user: User;
}

// Projects - نوع الطلب فقط، الاستجابة مرنة لأن الصفحات تستخدم أنواعها الخاصة
export interface CreateProjectRequest {
  name: string;
  department_id?: number | null;
  // @deprecated The manager_id column was removed during the scoped-roles
  // unification. Field kept for form compatibility; submitted value is
  // ignored by the server. Use `manager_user_id` instead when assigning
  // someone other than the creator.
  manager_id?: number | null;
  // Optional explicit manager override at creation time:
  //   - omitted / null / equal to creator.id ⇒ creator becomes manager.
  //   - set to another user id ⇒ server re-validates (active + same org +
  //     in-scope + eligible) and assigns ScopedRole::PROJECT_MANAGER to
  //     that user instead. See the spec at
  //     docs/superpowers/specs/2026-06-24-assignable-project-manager-design.md
  manager_user_id?: number | null;
  description?: string | null;
  objectives?: string[] | null;
  in_scope?: string[] | null;
  out_of_scope?: string[] | null;
  status?: ProjectStatus;
  priority?: Priority;
  start_date?: string | null;
  end_date?: string | null;
  budget?: string | null;
  human_resources?: string | null;
  technical_resources?: string | null;
  financial_resources?: string | null;
}

// Tasks - نوع الطلب فقط، الاستجابة مرنة
// ملاحظة: project_id يمكن أن يكون null/undefined لكن يتم التحقق منه في النموذج قبل الإرسال
export interface CreateTaskRequest {
  project_id?: number | null;
  title: string;
  milestone_id?: number | null;
  parent_id?: number | null;
  assigned_to?: number | null;
  description?: string | null;
  status?: TaskStatus | string;
  priority?: Priority | string;
  start_date?: string | null;
  due_date?: string | null;
  estimated_hours?: number | null;
  // Polymorphic origin (Direction B): a task may originate from a
  // Recommendation (meeting-issued action). Optional; server validates.
  source_type?: string | null;
  source_id?: number | null;
}

// Unified Tasks - طلبات موديول المهام الموحد
export interface CreateUnifiedTaskRequest {
  type?: TaskType;
  title: string;
  description?: string | null;
  status?: TaskStatus | string;
  priority?: Priority | string;
  progress?: number;
  start_date?: string | null;
  due_date?: string | null;
  estimated_hours?: number | null;
  project_id?: number | null;
  department_id?: number | null;
  milestone_id?: number | null;
  parent_id?: number | null;
  assigned_to?: number | null;
  owner_id?: number | null;
  is_private?: boolean;
  recurrence_rule?: string | null;
  // Polymorphic origin (Direction B)
  source_type?: string | null;
  source_id?: number | null;
}
