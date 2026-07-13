import React, { useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { IconPlus, IconTrash } from '@tabler/icons-react';
import { Button, Card, CardContent, CardHeader, CardTitle, IconButton, Input, Skeleton, Textarea } from '@shared/ui';
import { useToast } from '@shared/ui/Toast';
import { useAuth } from '@shared/contexts/AuthContext';
import { projectsApi } from '@entities/project';
import { usersApi } from '@entities/user';
import { risksApi } from '@entities/risk';
import { portfoliosApi, programsApi } from '@entities/strategy';
import { meetingsApi, meetingCategoriesApi, agendaItemsApi } from '@features/meetings/api';
import type { Meeting, DecidableAlias, MeetingCategory } from '@features/meetings/types';
import {
  useMeetingForm, MeetingDetailsSection, MeetingAttendeesPicker,
} from './form';
import { MeetingAgenda } from './view';

export interface MeetingFormProps {
  mode?: 'page' | 'modal';
  initial?: Partial<Meeting>;
  prefill?: { subject_type: DecidableAlias; subject_id: number };
  onSuccess?: (meeting: Meeting) => void;
  onCancel?: () => void;
}

const MeetingForm: React.FC<MeetingFormProps> = ({ mode: _mode = 'page', initial, prefill, onSuccess, onCancel }) => {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const { id } = useParams<{ id: string }>();
  const { showToast } = useToast();
  const { user } = useAuth();

  const [fetchedInitial, setFetchedInitial] = useState<Partial<Meeting> | undefined>(initial);
  const [loadingInitial, setLoadingInitial] = useState(!!id && !initial);

  useEffect(() => {
    if (id && !initial) {
      setLoadingInitial(true);
      meetingsApi.getOne(Number(id))
        .then((data) => setFetchedInitial(data as Meeting))
        .catch(() => {
          showToast('error', t('common.error_occurred'));
          navigate('/strategy/meetings');
        })
        .finally(() => setLoadingInitial(false));
    }
  }, [id, initial, navigate, showToast, t]);

  const { formData, setField, save, isLoading } = useMeetingForm(fetchedInitial, prefill);

  const [projects, setProjects] = useState<{ value: number; label: string }[]>([]);
  const [portfolios, setPortfolios] = useState<{ value: number; label: string }[]>([]);
  const [programs, setPrograms] = useState<{ value: number; label: string }[]>([]);
  const [risks, setRisks] = useState<{ value: number; label: string }[]>([]);
  const [users, setUsers] = useState<{ value: number; label: string }[]>([]);
  const [categories, setCategories] = useState<MeetingCategory[]>([]);

  // Create-mode only: agenda points staged locally, persisted after the meeting is created.
  const [agendaPoints, setAgendaPoints] = useState<{ title: string; description: string }[]>([]);
  const [ptTitle, setPtTitle] = useState('');
  const [ptDesc, setPtDesc] = useState('');

  const addPoint = () => {
    if (!ptTitle.trim()) return;
    setAgendaPoints((prev) => [...prev, { title: ptTitle.trim(), description: ptDesc.trim() }]);
    setPtTitle('');
    setPtDesc('');
  };
  const removePoint = (i: number) => setAgendaPoints((prev) => prev.filter((_, idx) => idx !== i));

  useEffect(() => {
    projectsApi.getAll()
      .then((r: unknown) => {
        const resp = r as { data: { id: number; name: string }[] };
        setProjects(resp.data.map((p) => ({ value: p.id, label: p.name })));
      }).catch(() => {});
    portfoliosApi.getList()
      .then((r: unknown) => {
        const resp = r as { data: { id: number; name: string }[] };
        setPortfolios(resp.data.map((p) => ({ value: p.id, label: p.name })));
      }).catch(() => {});
    programsApi.getList()
      .then((r: unknown) => {
        const resp = r as { data: { id: number; name: string }[] };
        setPrograms(resp.data.map((p) => ({ value: p.id, label: p.name })));
      }).catch(() => {});
    risksApi.list()
      .then((r: unknown) => {
        const resp = r as { data: { id: number; name: string }[] };
        setRisks(resp.data.map((p) => ({ value: p.id, label: p.name })));
      }).catch(() => {});
    usersApi.getList()
      .then((r: unknown) => {
        const list = (Array.isArray(r) ? r : (r as { data?: { id: number; name: string }[] }).data) ?? [];
        setUsers(list.map((u) => ({ value: u.id, label: u.name })));
      }).catch(() => {});
    meetingCategoriesApi.getAll(true)
      .then((r) => setCategories(r.data ?? []))
      .catch(() => {});
  }, []);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    try {
      const meeting = await save();
      if (!fetchedInitial?.id && agendaPoints.length > 0) {
        await Promise.all(agendaPoints.map((p) =>
          agendaItemsApi.create(meeting.id, { title: p.title, description: p.description || null })));
      }
      showToast('success', t(fetchedInitial?.id ? 'meetings.meeting.messages.updated' : 'meetings.meeting.messages.created'));
      if (onSuccess) {
        onSuccess(meeting);
      } else {
        navigate(`/strategy/meetings/${meeting.id}`);
      }
    } catch (err: unknown) {
      const msg = typeof err === 'object' && err !== null && 'message' in err && typeof err.message === 'string'
        ? err.message
        : t('common.error_occurred');
      showToast('error', msg);
    }
  };

  const organizerOptions = user ? [{ value: user.id, label: user.name }] : [];

  if (loadingInitial) {
    return (
      <div className="space-y-4">
        <Skeleton className="h-48 w-full" />
        <Skeleton className="h-32 w-full" />
      </div>
    );
  }

  return (
    <form onSubmit={handleSubmit} className="space-y-4">
      <MeetingDetailsSection
        data={formData}
        onChange={setField}
        organizerOptions={organizerOptions}
        projects={projects}
        portfolios={portfolios}
        programs={programs}
        risks={risks}
        categories={categories}
        linkDisabled={Boolean(prefill)}
      />
      <MeetingAttendeesPicker data={formData} onChange={setField} userOptions={users.length ? users : organizerOptions} />

      {fetchedInitial?.id ? (
        <MeetingAgenda meetingId={fetchedInitial.id} currentUserId={user?.id} />
      ) : (
        <Card>
          <CardHeader>
            <CardTitle>{t('meetings.agenda.title')}</CardTitle>
          </CardHeader>
          <CardContent className="space-y-3">
            <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
              {/* عمود الإضافة */}
              <div className="space-y-2 rounded-md border border-[var(--border-default)] p-3">
                <Input
                  value={ptTitle}
                  onChange={(e) => setPtTitle(e.target.value)}
                  onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); addPoint(); } }}
                  placeholder={t('meetings.agenda.item_title_placeholder')}
                />
                <Textarea
                  value={ptDesc}
                  onChange={(e) => setPtDesc(e.target.value)}
                  placeholder={t('meetings.agenda.item_desc_placeholder')}
                  rows={2}
                />
                <div className="flex justify-end">
                  <Button type="button" size="sm" leftIcon={<IconPlus className="h-4 w-4" />} onClick={addPoint} disabled={!ptTitle.trim()}>
                    {t('meetings.agenda.add_button')}
                  </Button>
                </div>
              </div>

              {/* عمود القائمة */}
              <div>
                <span className="block text-sm font-medium text-[var(--text-secondary)] mb-1">{t('meetings.agenda.approved_list')}</span>
                {agendaPoints.length === 0 ? (
                  <p className="rounded-md border border-dashed border-[var(--border-default)] px-3 py-4 text-center text-sm text-[var(--text-tertiary)]">
                    {t('meetings.agenda.empty_approved')}
                  </p>
                ) : (
                  <ol className="space-y-2">
                    {agendaPoints.map((p, i) => (
                      <li key={i} className="flex items-start gap-2 rounded-md border border-[var(--border-default)] p-3">
                        <span className="mt-0.5 text-sm font-semibold text-[var(--text-tertiary)]">{i + 1}.</span>
                        <div className="flex-1">
                          <p className="text-sm font-medium text-[var(--text-primary)]">{p.title}</p>
                          {p.description && <p className="mt-0.5 text-sm text-[var(--text-secondary)]">{p.description}</p>}
                        </div>
                        <IconButton type="button" variant="danger" size="xs" onClick={() => removePoint(i)}
                          aria-label={t('meetings.agenda.delete')} title={t('meetings.agenda.delete')}>
                          <IconTrash className="h-4 w-4" />
                        </IconButton>
                      </li>
                    ))}
                  </ol>
                )}
              </div>
            </div>
            <p className="text-xs text-[var(--text-tertiary)]">{t('meetings.agenda.create_hint')}</p>
          </CardContent>
        </Card>
      )}

      <div className="flex justify-end gap-2">
        <Button type="button" variant="ghost" onClick={() => (onCancel ? onCancel() : navigate(-1))}>
          {t('meetings.meeting.form.cancel')}
        </Button>
        <Button type="submit" disabled={isLoading}>
          {t(fetchedInitial?.id ? 'meetings.meeting.form.submit_update' : 'meetings.meeting.form.submit_create')}
        </Button>
      </div>
    </form>
  );
};

export default MeetingForm;
