import React from 'react';
import { useTranslation } from 'react-i18next';
import { Card, CardContent, CardHeader, CardTitle, Input, Textarea, Select } from '@shared/ui';
import type { RecommendationFormData } from './useRecommendationForm';

interface Props {
  data: RecommendationFormData;
  onChange: <K extends keyof RecommendationFormData>(key: K, value: RecommendationFormData[K]) => void;
  decisionOptions: { value: number; label: string }[];
  userOptions: { value: number; label: string }[];
  decisionDisabled?: boolean;
}

const RecommendationDetailsSection: React.FC<Props> = ({
  data,
  onChange,
  decisionOptions,
  userOptions,
  decisionDisabled,
}) => {
  const { t } = useTranslation();
  return (
    <Card>
      <CardHeader>
        <CardTitle>{t('meetings.recommendation.form.create_title')}</CardTitle>
      </CardHeader>
      <CardContent className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <Select
          label={t('meetings.recommendation.form.select_decision')}
          value={String(data.decision_id)}
          onChange={(e) => onChange('decision_id', Number(e.target.value))}
          options={[
            { value: '', label: t('meetings.recommendation.form.select_decision') },
            ...decisionOptions.map((o) => ({ value: String(o.value), label: o.label })),
          ]}
          disabled={decisionDisabled}
          required
        />
        <Input
          label={t('meetings.recommendation.fields.title')}
          value={data.title}
          onChange={(e) => onChange('title', e.target.value)}
          required
        />
        <Select
          label={t('meetings.recommendation.fields.priority')}
          value={data.priority}
          onChange={(e) => onChange('priority', e.target.value as RecommendationFormData['priority'])}
          options={[
            { value: 'low', label: t('meetings.recommendation.priorities.low') },
            { value: 'medium', label: t('meetings.recommendation.priorities.medium') },
            { value: 'high', label: t('meetings.recommendation.priorities.high') },
            { value: 'critical', label: t('meetings.recommendation.priorities.critical') },
          ]}
        />
        <Select
          label={t('meetings.recommendation.form.select_assignee')}
          value={String(data.assignee_id)}
          onChange={(e) => onChange('assignee_id', e.target.value ? Number(e.target.value) : '')}
          options={[
            { value: '', label: '—' },
            ...userOptions.map((o) => ({ value: String(o.value), label: o.label })),
          ]}
        />
        <Input
          type="date"
          label={t('meetings.recommendation.fields.due_date')}
          value={data.due_date}
          onChange={(e) => onChange('due_date', e.target.value)}
        />
        <Textarea
          label={t('meetings.recommendation.fields.description')}
          value={data.description}
          onChange={(e) => onChange('description', e.target.value)}
          rows={3}
        />
      </CardContent>
    </Card>
  );
};

export default RecommendationDetailsSection;
