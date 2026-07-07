import { useCallback, useEffect, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { recommendationsApi } from '@features/meetings/api';
import type { Recommendation } from '@features/meetings/types';
import type { RecommendationListFilters, RecommendationPaginated } from './types';

const EMPTY = { currentPage: 1, lastPage: 1, total: 0 };

export function useRecommendationsList() {
  const [searchParams] = useSearchParams();
  const [recommendations, setRecommendations] = useState<Recommendation[]>([]);
  const [loading, setLoading] = useState(true);
  const [pagination, setPagination] = useState(EMPTY);
  const [filters, setFiltersState] = useState<RecommendationListFilters>(() => ({
    status: '',
    priority: '',
    decision_id: searchParams.get('decision_id') ?? '',
    assignee_id: '',
    overdue: false,
    page: 1,
  }));

  const fetch = useCallback(async () => {
    setLoading(true);
    try {
      const params: Record<string, string> = { page: String(filters.page) };
      if (filters.status) params.status = filters.status;
      if (filters.priority) params.priority = filters.priority;
      if (filters.decision_id) params.decision_id = filters.decision_id;
      if (filters.assignee_id) params.assignee_id = filters.assignee_id;
      if (filters.overdue) params.overdue = '1';

      const res = (await recommendationsApi.getAll(params)) as RecommendationPaginated;
      setRecommendations(res.data);
      setPagination({ currentPage: res.current_page, lastPage: res.last_page, total: res.total });
    } catch (err) {
      console.error('Failed to fetch recommendations:', err);
      setRecommendations([]);
    } finally {
      setLoading(false);
    }
  }, [filters]);

  useEffect(() => {
    fetch();
  }, [fetch]);

  const setFilter = useCallback(
    <K extends keyof RecommendationListFilters>(key: K, value: RecommendationListFilters[K]) => {
      setFiltersState((cur) => ({
        ...cur,
        [key]: value,
        page: key === 'page' ? (value as number) : 1,
      }));
    },
    [],
  );

  const resetFilters = useCallback(() => {
    setFiltersState({
      status: '',
      priority: '',
      decision_id: '',
      assignee_id: '',
      overdue: false,
      page: 1,
    });
  }, []);

  return { recommendations, loading, pagination, filters, setFilter, resetFilters, refetch: fetch };
}
