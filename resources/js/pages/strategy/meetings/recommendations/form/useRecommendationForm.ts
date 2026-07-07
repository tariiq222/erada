import { useCallback, useState } from 'react';
import { recommendationsApi } from '@features/meetings/api';
import type { Recommendation, RecommendationPriority, RecommendationCreatePayload } from '@features/meetings/types';

export interface RecommendationFormData {
  decision_id: number | '';
  title: string;
  description: string;
  priority: RecommendationPriority;
  assignee_id: number | '';
  due_date: string;
}

const empty: RecommendationFormData = {
  decision_id: '',
  title: '',
  description: '',
  priority: 'medium',
  assignee_id: '',
  due_date: '',
};

export function useRecommendationForm(initial?: Partial<Recommendation>) {
  const [data, setData] = useState<RecommendationFormData>(() => ({
    ...empty,
    ...(initial
      ? {
          decision_id: initial.decision_id ?? '',
          title: initial.title ?? '',
          description: initial.description ?? '',
          priority: initial.priority ?? 'medium',
          assignee_id: initial.assignee_id ?? '',
          due_date: initial.due_date ?? '',
        }
      : {}),
  }));
  const [isLoading, setIsLoading] = useState(false);

  const setField = useCallback(
    <K extends keyof RecommendationFormData>(key: K, value: RecommendationFormData[K]) => {
      setData((cur) => ({ ...cur, [key]: value }));
    },
    [],
  );

  const save = useCallback(async (): Promise<Recommendation> => {
    setIsLoading(true);
    try {
      const payload: RecommendationCreatePayload = {
        kind: 'action_item',
        decision_id: Number(data.decision_id),
        title: data.title,
        description: data.description || null,
        priority: data.priority,
        assignee_id: data.assignee_id ? Number(data.assignee_id) : null,
        due_date: data.due_date || null,
      };
      const result = initial?.id
        ? await recommendationsApi.update(initial.id, payload)
        : await recommendationsApi.create(payload);
      return result as Recommendation;
    } finally {
      setIsLoading(false);
    }
  }, [data, initial?.id]);

  return { formData: data, setField, save, isLoading };
}
