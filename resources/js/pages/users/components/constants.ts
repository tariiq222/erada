export const roleLabels: Record<string, string> = {
  super_admin: 'role.super_admin',
  admin: 'role.admin',
  project_manager: 'role.project_manager',
  team_member: 'role.team_member',
  viewer: 'role.viewer',
};

export const roleColors: Record<string, 'default' | 'success' | 'warning' | 'danger' | 'accent'> = {
  super_admin: 'danger',
  admin: 'accent',
  project_manager: 'warning',
  team_member: 'success',
  viewer: 'default',
};
