export type IncidentStatus =
  | 'draft'
  | 'new'
  | 'under_review'
  | 'pending_info'
  | 'in_progress'
  | 'resolved'
  | 'closed'
  | 'rejected'
  | 'archived';

export type SeverityLevel = 'low' | 'medium' | 'high' | 'critical';

export interface ReportableType {
  id: number;
  name: string;
}

export interface Category {
  id: number;
  name: string;
  severity_level: string;
  requires_reportable_type?: boolean;
  reportableTypes?: ReportableType[];
}

export interface Employee {
  id: number;
  name: string;
  employee_number: string;
}

export interface StatusHistoryEntry {
  id: number;
  from_status: IncidentStatus | null;
  to_status: IncidentStatus;
  reason: string | null;
  changed_by: { id: number; name: string } | null;
  created_at: string;
}

export interface Comment {
  id: number;
  content: string;
  user: { id: number; name: string } | null;
  created_at: string;
}

export interface Incident {
  id: number;
  report_number: string;
  description: string | null;
  incident_datetime: string | null;
  location: string | null;
  is_patient_related: boolean;
  patient_file_number: string | null;
  patient_name: string | null;
  severity_level: SeverityLevel;
  status: IncidentStatus;
  incident_type: Category | null;
  reportable_incident_type: ReportableType | null;
  reporter: { id: number; name: string; employee_number: string } | null;
  assigned_to: { id: number; name: string } | null;
  actions_taken: string | null;
  contributing_factors: string[] | string | null;
  immediate_action_required: boolean;
  is_confidential: boolean;
  due_date: string | null;
  informed_authority: boolean;
  authority_informed_at: string | null;
  authority_response: string | null;
  closure_reason: string | null;
  status_history?: StatusHistoryEntry[];
  comments?: Comment[];
  created_at: string;
  updated_at: string;
}

export interface PaginatedResponse {
  data: Incident[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

export interface IncidentFormData {
  incident_type_id: string;
  reportable_incident_type_id: string;
  description: string;
  incident_datetime: string;
  is_patient_related: boolean;
  patient_file_number: string;
  patient_name: string;
  severity_level: SeverityLevel;
  actions_taken: string;
  contributing_factors: string[];
  immediate_action_required: boolean;
  is_confidential: boolean;
}
