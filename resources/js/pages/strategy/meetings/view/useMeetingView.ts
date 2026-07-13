import { useCallback, useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { useAuth } from '@shared/contexts/AuthContext';
import { useToast } from '@shared/ui/Toast';
import { meetingsApi } from '@features/meetings/api';
import type { Meeting } from '@features/meetings/types';

const messageFor = (err: unknown, fallback: string): string =>
  typeof err === 'object' && err !== null && 'message' in err && typeof err.message === 'string'
    ? err.message
    : fallback;

export function useMeetingView(id: string | undefined) {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const { user } = useAuth();
  const { showToast } = useToast();
  const [meeting, setMeeting] = useState<Meeting | null>(null);
  const [loading, setLoading] = useState(true);
  const [deleting, setDeleting] = useState(false);

  const fetch = useCallback(async () => {
    if (!id) return;
    setLoading(true);
    try {
      const data = (await meetingsApi.getOne(Number(id))) as Meeting;
      setMeeting(data);
    } catch (err) {
      console.error('Failed to fetch meeting:', err);
      showToast('error', t('common.error_occurred'));
      navigate('/strategy/meetings');
    } finally {
      setLoading(false);
    }
  }, [id, navigate, showToast, t]);

  useEffect(() => { fetch(); }, [fetch]);

  const remove = useCallback(async () => {
    if (!meeting) return;
    setDeleting(true);
    try {
      await meetingsApi.delete(meeting.id);
      showToast('success', t('meetings.meeting.messages.deleted'));
      navigate('/strategy/meetings');
    } catch (err: unknown) {
      showToast('error', messageFor(err, t('common.error_occurred')));
    } finally {
      setDeleting(false);
    }
  }, [meeting, navigate, showToast, t]);

  const start = useCallback(async () => {
    if (!meeting) return;
    try {
      await meetingsApi.start(meeting.id);
      showToast('success', t('meetings.meeting.messages.started'));
      await fetch();
    } catch (err: unknown) {
      const msg = typeof err === 'object' && err !== null && 'message' in err && typeof err.message === 'string'
        ? err.message
        : t('common.error_occurred');
      showToast('error', msg);
    }
  }, [meeting, fetch, showToast, t]);

  const complete = useCallback(async () => {
    if (!meeting) return;
    try {
      await meetingsApi.complete(meeting.id);
      showToast('success', t('meetings.meeting.messages.completed_msg'));
      await fetch();
    } catch (err: unknown) {
      const msg = typeof err === 'object' && err !== null && 'message' in err && typeof err.message === 'string'
        ? err.message
        : t('common.error_occurred');
      showToast('error', msg);
    }
  }, [meeting, fetch, showToast, t]);

  const cancel = useCallback(async () => {
    if (!meeting) return;
    try {
      await meetingsApi.cancel(meeting.id);
      showToast('success', t('meetings.meeting.messages.cancelled_msg'));
      await fetch();
    } catch (err: unknown) {
      showToast('error', messageFor(err, t('common.error_occurred')));
    }
  }, [meeting, fetch, showToast, t]);

  const updateMinutes = useCallback(async (minutes: string) => {
    if (!meeting) return;
    await meetingsApi.updateMinutes(meeting.id, minutes);
    showToast('success', t('meetings.meeting.messages.minutes_saved'));
    await fetch();
  }, [meeting, fetch, showToast, t]);

  return {
    meeting, loading, deleting, currentUserId: user?.id ?? null,
    refetch: fetch, remove, start, complete, cancel, updateMinutes,
  };
}
