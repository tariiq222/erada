/**
 * Survey entity — model (types & interfaces).
 */

export interface Survey {
  id: number;
  code: string;
  title: string;
  description: string | null;
  type: 'initial' | 'periodic';
  status: 'draft' | 'published' | 'closed' | 'archived';
  category: string | null;
  is_public: boolean;
  requires_auth: boolean;
  allow_multiple_responses: boolean;
  allow_edit_response: boolean;
  accepting_responses: boolean;
  responses_count: number;
  fields_count: number;
  fields: SurveyField[];
  published_at: string | null;
  starts_at: string | null;
  ends_at: string | null;
  welcome_message: string | null;
  thank_you_message: string | null;
  consent_required: boolean;
  consent_text: string | null;
  created_at: string;
  public_url: string;
}

export interface SurveyField {
  id: number;
  survey_id: number;
  section_id: number | null;
  field_key: string;
  name: string;
  label: string;
  description: string | null;
  type: string;
  config: Record<string, any>;
  is_required: boolean;
  is_visible: boolean;
  order: number;
}

export interface SurveyResponse {
  id: number;
  survey_id: number;
  respondent_type: 'public' | 'user';
  respondent_id: number | null;
  respondent_name: string | null;
  respondent_email: string | null;
  respondent_phone: string | null;
  status: 'submitted' | 'invalid' | 'flagged';
  submitted_at: string;
  completion_time: number | null;
  answers: SurveyAnswer[];
}

export interface SurveyAnswer {
  id: number;
  response_id: number;
  field_id: number;
  field_key: string;
  field?: SurveyField;
  answer_value: any;
  answer_text: string | null;
  answer_number: number | null;
  answer_date: string | null;
}

export interface CreateSurveyRequest {
  title: string;
  description?: string;
  type: 'initial' | 'periodic';
  category?: string;
  is_public?: boolean;
  requires_auth?: boolean;
  allow_multiple_responses?: boolean;
  allow_edit_response?: boolean;
  starts_at?: string | null;
  ends_at?: string | null;
  consent_required?: boolean;
  consent_text?: string;
  welcome_message?: string;
  thank_you_message?: string;
}

export interface CreateFieldRequest {
  field_key: string;
  name: string;
  label: string;
  description?: string;
  type: string;
  config?: Record<string, any>;
  is_required?: boolean;
  section_id?: number | null;
}
