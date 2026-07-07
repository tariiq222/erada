export const statusOptions = [
  { value: 'todo', labelKey: 'status.todo' },
  { value: 'in_progress', labelKey: 'status.in_progress' },
  { value: 'in_review', labelKey: 'status.in_review' },
  { value: 'completed', labelKey: 'status.completed' },
];

export const priorityOptions = [
  { value: 'low', labelKey: 'priority.low' },
  { value: 'medium', labelKey: 'priority.medium' },
  { value: 'high', labelKey: 'priority.high' },
  { value: 'urgent', labelKey: 'priority.urgent' },
];

export const emptyTaskFormData = {
  project_id: '',
  milestone_id: '',
  parent_id: '',
  assigned_to: '',
  title: '',
  description: '',
  status: 'todo',
  priority: 'medium',
  start_date: '',
  due_date: '',
  estimated_hours: '',
};

export const emptyMilestoneFormData = {
  name: '',
  description: '',
  duration_value: '',
  duration_unit: 'day' as const,
};
