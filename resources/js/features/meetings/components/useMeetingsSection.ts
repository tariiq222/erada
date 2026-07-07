import { useCallback, useEffect, useState } from 'react';
import { meetingsApi } from '@features/meetings/api';
import type { Meeting, DecidableAlias } from '@features/meetings/types';

interface Options {
  subject_type: DecidableAlias;
  subject_id: number;
}

interface Paginated<T> {
  data: T[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

export function useMeetingsSection({ subject_type, subject_id }: Options) {
  const [meetings, setMeetings] = useState<Meeting[]>([]);
  const [loading, setLoading] = useState(true);

  const fetch = useCallback(async () => {
    setLoading(true);
    try {
      const res = (await meetingsApi.getAll({
        subject_type,
        subject_id,
        per_page: '5',
      } as unknown as Record<string, string>)) as Paginated<Meeting>;
      setMeetings(res.data);
    } catch (err) {
      console.error('Failed to fetch meetings:', err);
      setMeetings([]);
    } finally {
      setLoading(false);
    }
  }, [subject_type, subject_id]);

  useEffect(() => {
    fetch();
  }, [fetch]);

  return { meetings, loading, refetch: fetch };
}
