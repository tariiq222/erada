export interface Expense {
  id: number;
  title: string;
  description: string | null;
  amount: number;
  category: string;
  expense_date: string;
  reference_number: string | null;
  attachment_path: string | null;
  task: { id: number; title: string } | null;
  creator: { id: number; name: string };
  created_at: string;
}

export interface ExpenseStats {
  total_expenses: number;
  budget: number;
  spent_amount: number;
  remaining: number;
  percentage_used: number;
  by_category: Record<string, { count: number; total: number }>;
}

export interface Categories {
  [key: string]: string;
}

export interface ProjectExpensesProps {
  projectId: number;
  budget: number | null;
  tasks?: { id: number; title: string }[];
}

export interface ExpenseFormData {
  title: string;
  description: string;
  amount: string;
  category: string;
  expense_date: string;
  task_id: string;
  reference_number: string;
}

export const categoryColors: Record<string, string> = {
  human_resources: 'bg-[var(--accent-subtle)] text-[var(--accent-default)]',
  materials: 'bg-[var(--status-warning-subtle)] text-[var(--status-warning-text)]',
  services: 'bg-[var(--accent-subtle)] text-[var(--accent-default)]',
  operational: 'bg-[var(--status-success-subtle)] text-[var(--status-success-text)]',
  travel: 'bg-[var(--accent-subtle)] text-[var(--accent-default)]',
  training: 'bg-[var(--accent-subtle)] text-[var(--accent-default)]',
  other: 'bg-[var(--surface-muted)] text-[var(--text-secondary)]',
};

export const EXPENSE_CATEGORIES: Categories = {
  human_resources: 'موارد بشرية',
  materials: 'مواد ومستلزمات',
  services: 'خدمات',
  operational: 'تشغيلية',
  travel: 'سفر وتنقل',
  training: 'تدريب',
  other: 'أخرى',
};

export const formatCurrency = (amount: number) => {
  return new Intl.NumberFormat('ar-EG-u-nu-latn', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  }).format(amount);
};
