export interface Survey {
  id: number;
  code: string;
  title: string;
  description: string | null;
  type: 'initial' | 'periodic';
  status: 'draft' | 'published' | 'closed' | 'archived';
  category: string | null;
  is_public: boolean;
  responses_count: number;
  fields_count: number;
  published_at: string | null;
  starts_at: string | null;
  ends_at: string | null;
  created_at: string;
}

export interface PaginatedResponse {
  data: Survey[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}
