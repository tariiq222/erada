export interface Department {
  id: number;
  name: string;
  code: string | null;
  description: string | null;
  parent_id: number | null;
  level: number;
  level_name: string;
  manager_id: number | null;
  is_active: boolean;
  parent: { id: number; name: string } | null;
  manager: { id: number; name: string } | null;
  employees_count: number;
}

export interface DepartmentPaginatedResponse {
  data: Department[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

export const DEPARTMENT_LEVEL_LABELS: Record<number, string> = {
  1: 'الإدارة العليا',
  2: 'إدارة تنفيذية',
  3: 'إدارة',
  4: 'قسم',
  5: 'وحدة',
  6: 'شعبة',
};

export const DEPARTMENT_LEVEL_COLORS: Record<number, string> = {
  1: 'primary',
  2: 'info',
  3: 'success',
  4: 'warning',
  5: 'default',
  6: 'secondary',
};

// Tree structure for org chart
export interface TreeDepartment {
  id: number;
  name: string;
  code: string | null;
  parent_id: number | null;
  level: number;
  level_name: string;
  manager: { id: number; name: string } | null;
  employees_count: number;
  children: TreeDepartment[];
}

// Border colors for org chart levels — semantic tokens only (single-blue system, no decorative colors)
export const LEVEL_BORDER_COLORS: Record<number, string> = {
  1: 'border-t-[var(--accent-default)]',
  2: 'border-t-[var(--accent-default)]',
  3: 'border-t-[var(--status-success)]',
  4: 'border-t-[var(--status-warning)]',
  5: 'border-t-[var(--border-strong)]',
  6: 'border-t-[var(--border-strong)]',
};

// Raw connector-line color per level, mirroring the card top-border accent.
// Levels 5/6 use --text-tertiary instead of the faint --border-strong so the
// lines stay visible against the board background.
export const LEVEL_LINE_COLORS: Record<number, string> = {
  1: 'var(--accent-default)',
  2: 'var(--accent-default)',
  3: 'var(--status-success)',
  4: 'var(--status-warning)',
  5: 'var(--text-tertiary)',
  6: 'var(--text-tertiary)',
};

export const levelLineColor = (level: number): string =>
  LEVEL_LINE_COLORS[level] ?? 'var(--text-tertiary)';
