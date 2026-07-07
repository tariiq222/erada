export interface User {
  id: number;
  name: string;
  email: string;
  phone: string | null;
  extension: string | null;
  job_title: string | null;
  is_active: boolean;
  department: {
    id: number;
    name: string;
  } | null;
  creator: {
    id: number;
    name: string;
  } | null;
  roles: string[];
  created_at: string;
}

export interface PaginatedResponse {
  data: User[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

export interface Department {
  id: number;
  name: string;
}
