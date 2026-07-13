import React, { useCallback, useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import {
  IconCheck, IconX, IconTrash, IconPlus, IconSend, IconChevronUp, IconChevronDown,
} from '@tabler/icons-react';
import {
  Button, Card, CardContent, CardHeader, CardTitle, Input, Textarea, Badge, Skeleton, IconButton,
} from '@shared/ui';
import { useToast } from '@shared/ui/Toast';
import { agendaItemsApi, meetingsApi } from '@features/meetings/api';
import type { AgendaItem } from '@features/meetings/types';

interface Props {
  meetingId: number;
  currentUserId?: number;
}

const statusTone = (s: AgendaItem['status']): 'success' | 'warning' | 'danger' =>
  s === 'approved' ? 'success' : s === 'pending' ? 'warning' : 'danger';

const MeetingAgenda: React.FC<Props> = ({ meetingId, currentUserId }) => {
  const { t } = useTranslation();
  const { showToast } = useToast();

  const [items, setItems] = useState<AgendaItem[]>([]);
  const [canManage, setCanManage] = useState(false);
  const [requestedAt, setRequestedAt] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  const [requesting, setRequesting] = useState(false);

  const [title, setTitle] = useState('');
  const [description, setDescription] = useState('');
  const [adding, setAdding] = useState(false);

  const load = useCallback(async () => {
    try {
      const res = await agendaItemsApi.list(meetingId);
      setItems(res.data);
      setCanManage(res.can_manage);
      setRequestedAt(res.agenda_requested_at);
    } catch (err: unknown) {
      const message = typeof err === 'object' && err !== null && 'message' in err && typeof err.message === 'string'
        ? err.message
        : t('common.error_occurred');
      showToast('error', message);
    } finally {
      setLoading(false);
    }
  }, [meetingId, showToast, t]);

  useEffect(() => { void load(); }, [load]);

  const addItem = async () => {
    if (!title.trim()) return;
    setAdding(true);
    try {
      const res = await agendaItemsApi.create(meetingId, { title: title.trim(), description: description.trim() || null });
      setItems((prev) => [...prev, res.item]);
      setTitle('');
      setDescription('');
      showToast('success', res.message);
    } catch (err: unknown) {
      const message = typeof err === 'object' && err !== null && 'message' in err && typeof err.message === 'string'
        ? err.message
        : t('common.error_occurred');
      showToast('error', message);
    } finally {
      setAdding(false);
    }
  };

  const applyUpdated = (updated: AgendaItem) =>
    setItems((prev) => prev.map((it) => (it.id === updated.id ? updated : it)));

  const approve = async (id: number) => {
    try { applyUpdated((await agendaItemsApi.approve(meetingId, id)).item); } catch { showToast('error', t('common.error_occurred')); }
  };
  const reject = async (id: number) => {
    try { applyUpdated((await agendaItemsApi.reject(meetingId, id)).item); } catch { showToast('error', t('common.error_occurred')); }
  };
  const remove = async (id: number) => {
    try { await agendaItemsApi.remove(meetingId, id); setItems((prev) => prev.filter((it) => it.id !== id)); }
    catch { showToast('error', t('common.error_occurred')); }
  };

  const sendRequest = async () => {
    setRequesting(true);
    try {
      const res = await meetingsApi.requestAgenda(meetingId);
      setRequestedAt(res.agenda_requested_at);
      showToast('success', t('meetings.agenda.request_sent'));
    } catch {
      showToast('error', t('common.error_occurred'));
    } finally {
      setRequesting(false);
    }
  };

  const approved = items.filter((it) => it.status === 'approved').sort((a, b) => a.position - b.position);
  const pending = items.filter((it) => it.status === 'pending');
  const rejected = items.filter((it) => it.status === 'rejected');

  const move = async (index: number, dir: -1 | 1) => {
    const target = index + dir;
    if (target < 0 || target >= approved.length) return;
    const reordered = [...approved];
    [reordered[index], reordered[target]] = [reordered[target], reordered[index]];
    setItems((prev) => [...reordered, ...prev.filter((it) => it.status !== 'approved')]);
    try { await agendaItemsApi.reorder(meetingId, reordered.map((it) => it.id)); }
    catch { showToast('error', t('common.error_occurred')); void load(); }
  };

  if (loading) {
    return (
      <Card><CardContent className="space-y-3 p-6"><Skeleton className="h-5 w-40" /><Skeleton className="h-20 w-full" /></CardContent></Card>
    );
  }

  return (
    <Card>
      <CardHeader className="flex flex-row items-center justify-between gap-2">
        <CardTitle>{t('meetings.agenda.title')}</CardTitle>
        {canManage && (
          <Button
            type="button" variant="outline" size="sm"
            leftIcon={<IconSend className="h-4 w-4" />}
            onClick={sendRequest} disabled={requesting}
          >
            {requestedAt ? t('meetings.agenda.resend_request') : t('meetings.agenda.request_button')}
          </Button>
        )}
      </CardHeader>
      <CardContent className="space-y-5">
        {requestedAt && (
          <p className="text-xs text-[var(--text-tertiary)]">
            {t('meetings.agenda.requested_hint')}
          </p>
        )}

        {/* Option 1: write your own point */}
        <div className="space-y-2 rounded-md border border-[var(--border-default)] p-3">
          <span className="block text-sm font-medium text-[var(--text-secondary)]">
            {canManage ? t('meetings.agenda.add_own') : t('meetings.agenda.add_as_invitee')}
          </span>
          <Input
            value={title}
            onChange={(e) => setTitle(e.target.value)}
            placeholder={t('meetings.agenda.item_title_placeholder')}
          />
          <Textarea
            value={description}
            onChange={(e) => setDescription(e.target.value)}
            placeholder={t('meetings.agenda.item_desc_placeholder')}
            rows={2}
          />
          <div className="flex justify-end">
            <Button type="button" size="sm" leftIcon={<IconPlus className="h-4 w-4" />} onClick={addItem} disabled={adding || !title.trim()}>
              {t('meetings.agenda.add_button')}
            </Button>
          </div>
        </div>

        {/* Approved agenda (the actual list) */}
        <div className="space-y-2">
          <span className="block text-sm font-medium text-[var(--text-secondary)]">{t('meetings.agenda.approved_list')}</span>
          {approved.length === 0 ? (
            <p className="rounded-md border border-dashed border-[var(--border-default)] px-3 py-4 text-center text-sm text-[var(--text-tertiary)]">
              {t('meetings.agenda.empty_approved')}
            </p>
          ) : (
            <ol className="space-y-2">
              {approved.map((it, i) => (
                <li key={it.id} className="flex items-start gap-2 rounded-md border border-[var(--border-default)] p-3">
                  <span className="mt-0.5 text-sm font-semibold text-[var(--text-tertiary)]">{i + 1}.</span>
                  <div className="flex-1">
                    <p className="text-sm font-medium text-[var(--text-primary)]">{it.title}</p>
                    {it.description && <p className="mt-0.5 text-sm text-[var(--text-secondary)]">{it.description}</p>}
                    {it.proposed_by && (
                      <p className="mt-1 text-xs text-[var(--text-tertiary)]">{t('meetings.agenda.proposed_by', { name: it.proposed_by.name })}</p>
                    )}
                  </div>
                  {canManage && (
                    <div className="flex items-center gap-1">
                      <IconButton type="button" size="xs" onClick={() => move(i, -1)} disabled={i === 0}
                        aria-label={t('meetings.agenda.move_up')} title={t('meetings.agenda.move_up')}>
                        <IconChevronUp className="h-4 w-4" />
                      </IconButton>
                      <IconButton type="button" size="xs" onClick={() => move(i, 1)} disabled={i === approved.length - 1}
                        aria-label={t('meetings.agenda.move_down')} title={t('meetings.agenda.move_down')}>
                        <IconChevronDown className="h-4 w-4" />
                      </IconButton>
                      <IconButton type="button" variant="danger" size="xs" onClick={() => remove(it.id)}
                        aria-label={t('meetings.agenda.delete')} title={t('meetings.agenda.delete')}>
                        <IconTrash className="h-4 w-4" />
                      </IconButton>
                    </div>
                  )}
                </li>
              ))}
            </ol>
          )}
        </div>

        {/* Pending review — managers review invitee submissions */}
        {canManage && pending.length > 0 && (
          <div className="space-y-2">
            <span className="block text-sm font-medium text-[var(--text-secondary)]">
              {t('meetings.agenda.pending_review')} <Badge variant="warning">{pending.length}</Badge>
            </span>
            <ul className="space-y-2">
              {pending.map((it) => (
                <li key={it.id} className="flex items-start gap-2 rounded-md border border-[var(--status-warning)]/40 bg-[var(--surface-subtle)] p-3">
                  <div className="flex-1">
                    <p className="text-sm font-medium text-[var(--text-primary)]">{it.title}</p>
                    {it.description && <p className="mt-0.5 text-sm text-[var(--text-secondary)]">{it.description}</p>}
                    {it.proposed_by && (
                      <p className="mt-1 text-xs text-[var(--text-tertiary)]">{t('meetings.agenda.proposed_by', { name: it.proposed_by.name })}</p>
                    )}
                  </div>
                  <div className="flex items-center gap-1">
                    <IconButton type="button" variant="success" size="xs" onClick={() => approve(it.id)}
                      aria-label={t('meetings.agenda.approve')} title={t('meetings.agenda.approve')}>
                      <IconCheck className="h-4 w-4" />
                    </IconButton>
                    <IconButton type="button" variant="danger" size="xs" onClick={() => reject(it.id)}
                      aria-label={t('meetings.agenda.reject')} title={t('meetings.agenda.reject')}>
                      <IconX className="h-4 w-4" />
                    </IconButton>
                  </div>
                </li>
              ))}
            </ul>
          </div>
        )}

        {/* Invitee sees their own pending/rejected items */}
        {!canManage && (
          <>
            {items.filter((it) => it.status !== 'approved' && it.proposed_by_id === currentUserId).map((it) => (
              <div key={it.id} className="flex items-center justify-between rounded-md border border-[var(--border-default)] p-3 text-sm">
                <span className="text-[var(--text-primary)]">{it.title}</span>
                <Badge variant={statusTone(it.status)}>{it.status_label}</Badge>
              </div>
            ))}
          </>
        )}

        {canManage && rejected.length > 0 && (
          <p className="text-xs text-[var(--text-tertiary)]">{t('meetings.agenda.rejected_count', { count: rejected.length })}</p>
        )}
      </CardContent>
    </Card>
  );
};

export default MeetingAgenda;
