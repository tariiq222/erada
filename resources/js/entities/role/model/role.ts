/**
 * Role entity — model (types/interfaces).
 */

export interface Role {
  id: number;
  name: string;
  display_name: string;
  guard_name: string;
  permissions: string[];
  permissions_count: number;
  users_count: number;
  is_system: boolean;
  created_at?: string;
  // New fields (API v2 — optional for backward compat)
  scope_type?: string;
  scoped_def_id?: number | null;
  label_ar?: string;
  label_en?: string;
  capabilities?: string[];
  /** Per-module reach cap: { module: 'own' | 'department' | 'all' }. */
  reach?: Record<string, string>;
  is_admin_role?: boolean;
}

export interface PermissionItem {
  name: string;
  display_name: string;
}

export interface PermissionCategory {
  category: string;
  permissions: PermissionItem[];
}

// ===== Permission matrix (resource × scope) =====

export interface ScopeOption {
  key: 'own' | 'department' | 'all';
  label: string;
  permission: string;
}

export interface ScopedAction {
  key: 'view' | 'edit' | 'create' | 'delete';
  label: string;
  permission?: string; // for create/delete
  scopes?: ScopeOption[]; // for view/edit
}

export interface ScopedResource {
  key: string;
  label: string;
  actions: ScopedAction[];
}

export interface FlatGroup {
  key: string;
  label: string;
  permissions: PermissionItem[];
}

export interface PermissionMatrix {
  scoped: ScopedResource[];
  flat: FlatGroup[];
}

// ===== Unified ability registry (single source for the role builder) =====

export interface Ability {
  id: string;
  label: string;
}

export interface AbilityGroup {
  key: string;
  label: string;
  store: 'engine' | 'flat';
  abilities: Ability[];
}

export interface AbilityRegistry {
  groups: AbilityGroup[];
}
