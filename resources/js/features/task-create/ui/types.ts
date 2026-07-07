export interface MilestoneOption {
  id: number;
  name: string;
  status: string;
  start_date?: string;
  due_date?: string;
}

export interface UserOption {
  id: number;
  name: string;
  email?: string;
}

export interface TaskOption {
  id: number;
  title: string;
}

export interface RecommendationOption {
  id: number;
  title: string;
  reference_number?: string;
  kind?: 'ruling' | 'action_item';
  status?: string;
  assignee_id?: number | null;
  due_date?: string | null;
  priority?: string;
}

export interface ProjectInfo {
  id: number;
  name: string;
  code: string;
  start_date?: string;
  end_date?: string;
  milestones?: MilestoneOption[];
}

export interface TaskFormData {
  milestone_id: string;
  parent_id: string;
  assigned_to: string;
  title: string;
  description: string;
  priority: string;
  start_date: string;
  due_date: string;
  estimated_hours: string;
  source_type: string;
  source_id: string;
}

export interface NewUserFormData {
  email: string;
}

export interface ValidationErrors {
  [key: string]: string[];
}

export interface CreateTaskModalProps {
  isOpen: boolean;
  onClose: () => void;
  projectId: number;
  project: ProjectInfo;
  onTaskCreated: () => void;
  // Optional meeting context — when set, the source picker shows open
  // recommendations from this meeting.
  meetingId?: number;
}

export const priorityOptions = [
  { value: 'low', label: 'منخفضة' },
  { value: 'medium', label: 'متوسطة' },
  { value: 'high', label: 'عالية' },
  { value: 'urgent', label: 'عاجلة' },
];

export const initialFormData: TaskFormData = {
  milestone_id: '',
  parent_id: '',
  assigned_to: '',
  title: '',
  description: '',
  priority: 'medium',
  start_date: '',
  due_date: '',
  estimated_hours: '',
  source_type: '',
  source_id: '',
};
