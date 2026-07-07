import { useCallback, useEffect, useState } from 'react';
import { meetingsApi } from '@features/meetings/api';
import type { Meeting } from '@features/meetings/types';
import type { MeetingListFilters, MeetingPaginated } from './types';

const EMPTY = { currentPage: 1, lastPage: 1, total: 0 };

export function useMeetingsList() {
  const [meetings, setMeetings] = useState<Meeting[]>([]);
  const [loading, setLoading] = useState(true);
  const [pagination, setPagination] = useState(EMPTY);
  const [filters, setFiltersState] = useState<MeetingListFilters>({
    status: '', subject_type: '', subject_id: '', from: '', to: '',
    pending_reminder: false, page: 1,
  });

  const fetch = useCallback(async () => {
    setLoading(true);
    try {
      const params: Record<string, string> = { page: String(filters.page) };
      if (filters.status) params.status = filters.status;
      if (filters.subject_type && filters.subject_type !== 'all') params.subject_type = filters.subject_type;
      if (filters.subject_id) params.subject_id = filters.subject_id;
      if (filters.from) params.from = filters.from;
      if (filters.to) params.to = filters.to;
      if (filters.pending_reminder) params.pending_reminder = '1';

      const res = (await meetingsApi.getAll(params)) as MeetingPaginated;
      setMeetings(res.data);
      setPagination({ currentPage: res.current_page, lastPage: res.last_page, total: res.total });
    } catch (err) {
      console.error('Failed to fetch meetings:', err);
      setMeetings([]);
    } finally {
      setLoading(false);
    }
  }, [filters]);

  useEffect(() => { fetch(); }, [fetch]);

  const setFilter = useCallback(
    <K extends keyof MeetingListFilters>(key: K, value: MeetingListFilters[K]) => {
      setFiltersState((cur) => ({ ...cur, [key]: value, page: key === 'page' ? (value as number) : 1 }));
    }, [],
  );

  const resetFilters = useCallback(() => {
    setFiltersState({ status: '', subject_type: '', subject_id: '', from: '', to: '', pending_reminder: false, page: 1 });
  }, []);

  return { meetings, loading, pagination, filters, setFilter, resetFilters, refetch: fetch };
}

export default useMeetingsList;
