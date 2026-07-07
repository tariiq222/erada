import type { Meeting } from '@features/meetings/types';

export interface MeetingPaginated {
  data: Meeting[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

export type MeetingListFilterKey =
  | 'status' | 'subject_type' | 'subject_id' | 'from' | 'to' | 'pending_reminder' | 'page';

export interface MeetingListFilters {
  status: string;
  subject_type: string;
  subject_id: string;
  from: string;
  to: string;
  pending_reminder: boolean;
  page: number;
}
