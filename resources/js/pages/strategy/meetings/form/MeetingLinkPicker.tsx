import React from 'react';
import { useTranslation } from 'react-i18next';
import { Card, CardContent, CardHeader, CardTitle, Select } from '@shared/ui';
import type { DecidableAlias } from '@features/meetings/types';
import type { MeetingFormData } from './useMeetingForm';

interface SubjectOption { value: number; label: string }

interface Props {
  data: MeetingFormData;
  onChange: <K extends keyof MeetingFormData>(key: K, value: MeetingFormData[K]) => void;
  projects: SubjectOption[];
  portfolios: SubjectOption[];
  programs: SubjectOption[];
  risks: SubjectOption[];
  disabled?: boolean;
}

const MeetingLinkPicker: React.FC<Props> = ({
  data, onChange, projects, portfolios, programs, risks, disabled,
}) => {
  const { t } = useTranslation();

  const opts = (): SubjectOption[] => {
    switch (data.subject_type) {
      case 'project':   return projects;
      case 'portfolio': return portfolios;
      case 'program':   return programs;
      case 'risk':      return risks;
      default:          return [];
    }
  };

  return (
    <Card>
      <CardHeader>
        <CardTitle>{t('meetings.meeting.fields.subject_type')}</CardTitle>
      </CardHeader>
      <CardContent className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <Select
          label={t('meetings.meeting.fields.subject_type')}
          value={data.subject_type}
          onChange={(e) => onChange('subject_type', e.target.value as DecidableAlias | '')}
          options={[
            { value: '', label: '—' },
            { value: 'project', label: t('meetings.entity_picker.select_type') + ': project' },
            { value: 'portfolio', label: t('meetings.entity_picker.select_type') + ': portfolio' },
            { value: 'program', label: t('meetings.entity_picker.select_type') + ': program' },
            { value: 'risk', label: t('meetings.entity_picker.select_type') + ': risk' },
          ]}
          disabled={disabled}
        />
        <Select
          label={t('meetings.meeting.fields.subject_id')}
          value={String(data.subject_id ?? '')}
          onChange={(e) => onChange('subject_id', e.target.value ? Number(e.target.value) : '')}
          options={[
            { value: '', label: '—' },
            ...opts().map((o) => ({ value: String(o.value), label: o.label })),
          ]}
          disabled={disabled || !data.subject_type}
        />
      </CardContent>
    </Card>
  );
};

export default MeetingLinkPicker;
