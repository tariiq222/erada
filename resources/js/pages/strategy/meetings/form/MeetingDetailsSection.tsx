import React from 'react';
import { useTranslation } from 'react-i18next';
import { Card, CardContent, CardHeader, CardTitle, Input, Select, Textarea } from '@shared/ui';
import type { DecidableAlias, MeetingCategory } from '@features/meetings/types';
import type { MeetingFormData } from './useMeetingForm';

interface SubjectOption { value: number; label: string }

interface Props {
  data: MeetingFormData;
  onChange: <K extends keyof MeetingFormData>(key: K, value: MeetingFormData[K]) => void;
  organizerOptions: { value: number; label: string }[];
  projects: SubjectOption[];
  portfolios: SubjectOption[];
  programs: SubjectOption[];
  risks: SubjectOption[];
  categories: MeetingCategory[];
  linkDisabled?: boolean;
}

const MeetingDetailsSection: React.FC<Props> = ({
  data, onChange, organizerOptions, projects, portfolios, programs, risks, categories, linkDisabled,
}) => {
  const { t } = useTranslation();

  const subjectOptions = (): SubjectOption[] => {
    switch (data.subject_type) {
      case 'project':   return projects;
      case 'portfolio': return portfolios;
      case 'program':   return programs;
      case 'risk':      return risks;
      default:          return [];
    }
  };

  // "مرتبط ب" merges entity links (project/portfolio/...) and admin-managed categories
  // into one dropdown. Categories use a `cat:<id>` value; selecting one clears the entity link.
  const linkValue = data.category_id ? `cat:${data.category_id}` : data.subject_type;

  const handleLinkChange = (raw: string) => {
    if (raw.startsWith('cat:')) {
      onChange('category_id', Number(raw.slice(4)));
      onChange('subject_type', '');
      onChange('subject_id', '');
    } else {
      onChange('subject_type', raw as DecidableAlias | '');
      onChange('subject_id', '');
      onChange('category_id', '');
    }
  };

  return (
    <Card>
      <CardHeader>
        <CardTitle>{t('meetings.meeting.detail.overview')}</CardTitle>
      </CardHeader>
      <CardContent className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <Input
          label={t('meetings.meeting.fields.title')}
          value={data.title}
          onChange={(e) => onChange('title', e.target.value)}
          required
        />
        <Input
          type="datetime-local"
          label={t('meetings.meeting.fields.scheduled_at')}
          value={data.scheduled_at.slice(0, 16)}
          onChange={(e) => onChange('scheduled_at', e.target.value ? new Date(e.target.value).toISOString() : '')}
          required
        />
        <Input
          type="number"
          label={t('meetings.meeting.fields.duration_minutes')}
          value={String(data.duration_minutes)}
          onChange={(e) => onChange('duration_minutes', Number(e.target.value))}
          min={5}
          max={1440}
          required
        />
        <Select
          label={t('meetings.meeting.fields.organizer')}
          value={String(data.organizer_id)}
          onChange={(e) => onChange('organizer_id', e.target.value ? Number(e.target.value) : '')}
          options={[
            { value: '', label: t('meetings.meeting.form.select_organizer') },
            ...organizerOptions.map((o) => ({ value: String(o.value), label: o.label })),
          ]}
          required
        />
        <Select
          label={t('meetings.meeting.fields.format')}
          value={data.format}
          onChange={(e) => {
            const next = e.target.value as MeetingFormData['format'];
            onChange('format', next);
            onChange(next === 'online' ? 'location' : 'virtual_link', '');
          }}
          options={[
            { value: 'in_person', label: t('meetings.meeting.fields.format_in_person') },
            { value: 'online', label: t('meetings.meeting.fields.format_online') },
          ]}
        />
        {data.format === 'in_person' ? (
          <Input
            label={t('meetings.meeting.fields.location')}
            value={data.location}
            onChange={(e) => onChange('location', e.target.value)}
          />
        ) : (
          <Input
            type="url"
            label={t('meetings.meeting.fields.virtual_link')}
            value={data.virtual_link}
            onChange={(e) => onChange('virtual_link', e.target.value)}
          />
        )}
        <Select
          label={t('meetings.meeting.fields.subject_type')}
          value={linkValue}
          onChange={(e) => handleLinkChange(e.target.value)}
          options={[
            { value: '', label: '—' },
            { value: 'project', label: t('meetings.entity_picker.type.project') },
            { value: 'portfolio', label: t('meetings.entity_picker.type.portfolio') },
            { value: 'program', label: t('meetings.entity_picker.type.program') },
            { value: 'risk', label: t('meetings.entity_picker.type.risk') },
            ...categories
              .filter((c) => c.is_active || c.id === data.category_id)
              .map((c) => ({ value: `cat:${c.id}`, label: c.name })),
          ]}
          disabled={linkDisabled}
        />
        {data.subject_type ? (
          <Select
            label={t('meetings.meeting.fields.subject_id')}
            value={String(data.subject_id ?? '')}
            onChange={(e) => onChange('subject_id', e.target.value ? Number(e.target.value) : '')}
            options={[
              { value: '', label: '—' },
              ...subjectOptions().map((o) => ({ value: String(o.value), label: o.label })),
            ]}
            disabled={linkDisabled}
          />
        ) : null}
        <div className="sm:col-span-2 lg:col-span-4">
          <Textarea
            label={t('meetings.meeting.fields.description')}
            value={data.description}
            onChange={(e) => onChange('description', e.target.value)}
            rows={3}
          />
        </div>
      </CardContent>
    </Card>
  );
};

export default MeetingDetailsSection;
