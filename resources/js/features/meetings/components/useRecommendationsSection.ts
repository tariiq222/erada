import { useCallback, useEffect, useState } from 'react';
import { recommendationsApi } from '@features/meetings/api';
import type { Recommendation } from '@features/meetings/types';

interface Paginated<T> {
  data: T[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

export function useRecommendationsSection({ decision_id }: { decision_id: number }) {
  const [recommendations, setRecommendations] = useState<Recommendation[]>([]);
  const [loading, setLoading] = useState(true);

  const fetch = useCallback(async () => {
    setLoading(true);
    try {
      const res = (await recommendationsApi.getAll({
        decision_id: String(decision_id),
        per_page: '5',
      })) as Paginated<Recommendation>;
      setRecommendations(res.data);
    } catch (err) {
      console.error('Failed to fetch recommendations:', err);
      setRecommendations([]);
    } finally {
      setLoading(false);
    }
  }, [decision_id]);

  useEffect(() => {
    fetch();
  }, [fetch]);

  return { recommendations, loading, refetch: fetch };
}
