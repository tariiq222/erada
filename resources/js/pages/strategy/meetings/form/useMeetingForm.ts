import { useCallback, useEffect, useState } from 'react';
import { meetingsApi } from '@features/meetings/api';
import type { Meeting, MeetingCreatePayload, DecidableAlias } from '@features/meetings/types';

export interface MeetingFormData {
  title: string;
  description: string;
  scheduled_at: string;
  duration_minutes: number;
  format: 'in_person' | 'online';
  location: string;
  virtual_link: string;
  agenda: string;
  organizer_id: number | '';
  subject_type: DecidableAlias | '';
  subject_id: number | '';
  category_id: number | '';
  attendee_ids: number[];
}

function toFormData(m?: Partial<Meeting>, prefill?: { subject_type: DecidableAlias; subject_id: number }): MeetingFormData {
  return {
    title: m?.title ?? '',
    description: m?.description ?? '',
    scheduled_at: m?.scheduled_at ?? new Date(Date.now() + 86400000).toISOString(),
    duration_minutes: m?.duration_minutes ?? 60,
    format: m?.virtual_link ? 'online' : 'in_person',
    location: m?.location ?? '',
    virtual_link: m?.virtual_link ?? '',
    agenda: m?.agenda ?? '',
    organizer_id: m?.organizer_id ?? '',
    subject_type: (m?.subject_type as DecidableAlias | undefined) ?? prefill?.subject_type ?? '',
    subject_id: m?.subject_id ?? prefill?.subject_id ?? '',
    category_id: m?.category_id ?? '',
    attendee_ids: m?.attendees?.map((a) => a.id) ?? [],
  };
}

export function useMeetingForm(
  initial?: Partial<Meeting>,
  prefill?: { subject_type: DecidableAlias; subject_id: number },
) {
  const [formData, setFormData] = useState<MeetingFormData>(() => toFormData(initial, prefill));
  const [isLoading, setIsLoading] = useState(false);

  // Re-initialise form when the meeting id changes (e.g. after async fetch in edit mode).
  // Gated on `initial?.id` so it never fires on every render or wipes user edits mid-typing.
  useEffect(() => {
    if (initial?.id !== undefined) {
      setFormData(toFormData(initial, prefill));
    }
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [initial?.id]);

  const setField = useCallback(<K extends keyof MeetingFormData>(key: K, value: MeetingFormData[K]) => {
    setFormData((prev) => ({ ...prev, [key]: value }));
  }, []);

  const save = useCallback(async (): Promise<Meeting> => {
    setIsLoading(true);
    try {
      const payload: MeetingCreatePayload = {
        title: formData.title,
        description: formData.description || null,
        scheduled_at: formData.scheduled_at,
        duration_minutes: formData.duration_minutes,
        location: formData.format === 'in_person' ? (formData.location || null) : null,
        virtual_link: formData.format === 'online' ? (formData.virtual_link || null) : null,
        agenda: formData.agenda || null,
        organizer_id: formData.organizer_id as number,
        subject_type: formData.subject_type || null,
        subject_id: (formData.subject_id as number) || null,
        category_id: (formData.category_id as number) || null,
        attendee_ids: formData.attendee_ids,
      };
      if (initial?.id) {
        return (await meetingsApi.update(initial.id, payload)) as Meeting;
      }
      return (await meetingsApi.create(payload)) as Meeting;
    } finally {
      setIsLoading(false);
    }
  }, [formData, initial?.id]);

  return { formData, setField, save, isLoading };
}
