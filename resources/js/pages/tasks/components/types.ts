export interface TimeIndicator {
  days_remaining: number | null;
  days_elapsed: number | null;
  total_days: number | null;
  time_progress: number | null;
  status: 'normal' | 'warning' | 'urgent' | 'overdue' | 'completed';
  has_due_date: boolean;
}

export interface TaskDetails {
  id: number;
  title: string;
  description: string | null;
  status: string;
  priority: string;
  start_date: string | null;
  due_date: string | null;
  completed_date: string | null;
  estimated_hours: number | null;
  actual_hours: number | null;
  time_indicator: TimeIndicator;
  project: { id: number; code: string; name: string } | null;
  milestone: { id: number; name: string } | null;
  assignee: { id: number; name: string; email: string } | null;
  creator: { id: number; name: string } | null;
  parent: { id: number; title: string } | null;
  subtasks: SubtaskDetails[];
  comments: Comment[];
}

export interface CommentAttachment {
  id: number;
  name: string;
  file_path: string;
  file_type: string;
  file_size: number;
  formatted_size: string;
  url: string;
}

export interface Comment {
  id: number;
  content: string;
  user: { id: number; name: string };
  mentioned_users: { id: number; name: string }[];
  attachments?: CommentAttachment[];
  created_at: string;
  updated_at: string;
}

export interface UserOption {
  id: number;
  name: string;
  email: string;
}

export interface SubtaskDetails {
  id: number;
  title: string;
  status: string;
  priority?: string;
  due_date?: string | null;
  assignee?: { id: number; name: string } | null;
}
