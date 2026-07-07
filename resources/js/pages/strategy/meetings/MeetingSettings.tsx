import React, { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { IconSettings, IconAdjustments, IconListTree, IconClock, IconGavel } from '@tabler/icons-react';
import {
  Button,
  Card,
  Input,
  Switch,
  Select,
  Skeleton,
  PageHeader,
  Tabs,
  TabsList,
  TabsTrigger,
  TabsContent,
} from '@shared/ui';
import { useToast } from '@shared/ui/Toast';
import { meetingSettingsApi, meetingCategoriesApi } from '@features/meetings/api';
import type { MeetingSettings, MeetingSettingsPayload, MeetingCategory } from '@features/meetings/types';
import MeetingCategoriesSettings from './CategoriesSettings';

const Field: React.FC<{ label: string; hint?: string; children: React.ReactNode }> = ({ label, hint, children }) => (
  <div className="space-y-1.5">
    <label className="block text-sm font-medium text-[var(--text-primary)]">{label}</label>
    {children}
    {hint && <p className="text-xs text-[var(--text-tertiary)]">{hint}</p>}
  </div>
);

const SectionCard: React.FC<{ icon: React.ElementType; title: string; children: React.ReactNode }> = ({
  icon: Icon,
  title,
  children,
}) => (
  <Card className="p-4 sm:p-6 border border-[var(--border-default)]">
    <div className="flex items-center gap-2 mb-4">
      <Icon className="h-4 w-4 text-[var(--text-tertiary)]" />
      <h3 className="text-base font-semibold text-[var(--text-primary)]">{title}</h3>
    </div>
    {children}
  </Card>
);

const GeneralSettings: React.FC = () => {
  const { t } = useTranslation();
  const { showToast } = useToast();

  const [form, setForm] = useState<MeetingSettingsPayload | null>(null);
  const [rolesText, setRolesText] = useState('');
  const [categories, setCategories] = useState<MeetingCategory[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [isSaving, setIsSaving] = useState(false);

  useEffect(() => {
    (async () => {
      setIsLoading(true);
      try {
        const [settingsRes, catsRes] = await Promise.all([
          meetingSettingsApi.get(),
          meetingCategoriesApi.getAll(true),
        ]);
        const s: MeetingSettings = settingsRes.data;
        setForm({
          default_duration_minutes: s.default_duration_minutes,
          reminder_window_hours: s.reminder_window_hours,
          attendee_roles: s.attendee_roles ?? [],
          default_category_id: s.default_category_id,
          agenda_request_enabled: s.agenda_request_enabled,
          agenda_request_lead_hours: s.agenda_request_lead_hours,
          decision_pending_expiry_days: s.decision_pending_expiry_days,
          recommendation_overdue_grace_days: s.recommendation_overdue_grace_days,
        });
        setRolesText((s.attendee_roles ?? []).join('، '));
        setCategories(catsRes.data ?? []);
      } catch {
        showToast('error', t('common.error_occurred'));
      } finally {
        setIsLoading(false);
      }
    })();
  }, [t, showToast]);

  const set = <K extends keyof MeetingSettingsPayload>(key: K, value: MeetingSettingsPayload[K]) =>
    setForm((prev) => (prev ? { ...prev, [key]: value } : prev));

  const handleSave = async () => {
    if (!form) return;
    const roles = rolesText
      .split(/[،,\n]/)
      .map((r) => r.trim())
      .filter(Boolean);
    if (roles.length === 0) {
      showToast('error', t('meetings.settings.roles_required'));
      return;
    }
    setIsSaving(true);
    try {
      await meetingSettingsApi.update({ ...form, attendee_roles: roles });
      showToast('success', t('meetings.settings.saved'));
    } catch {
      showToast('error', t('common.save_error'));
    } finally {
      setIsSaving(false);
    }
  };

  if (isLoading || !form) {
    return (
      <Card className="p-6 space-y-4">
        {[...Array(6)].map((_, i) => (
          <Skeleton key={i} className="h-10 w-full" />
        ))}
      </Card>
    );
  }

  return (
    <div className="space-y-6">
      <SectionCard icon={IconAdjustments} title={t('meetings.settings.section_general')}>
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <Field label={t('meetings.settings.default_duration')} hint={t('meetings.settings.minutes_hint')}>
            <Input
              type="number"
              min={5}
              max={1440}
              value={String(form.default_duration_minutes)}
              onChange={(e) => set('default_duration_minutes', Number(e.target.value))}
            />
          </Field>
          <Field label={t('meetings.settings.reminder_window')} hint={t('meetings.settings.hours_hint')}>
            <Input
              type="number"
              min={1}
              max={336}
              value={String(form.reminder_window_hours)}
              onChange={(e) => set('reminder_window_hours', Number(e.target.value))}
            />
          </Field>
          <Field label={t('meetings.settings.default_category')}>
            <Select
              placeholder={t('meetings.settings.no_default_category')}
              value={form.default_category_id == null ? '' : String(form.default_category_id)}
              onChange={(e) => set('default_category_id', e.target.value ? Number(e.target.value) : null)}
              options={categories.map((c) => ({ value: String(c.id), label: c.name }))}
            />
          </Field>
          <Field label={t('meetings.settings.attendee_roles')} hint={t('meetings.settings.roles_hint')}>
            <Input value={rolesText} onChange={(e) => setRolesText(e.target.value)} />
          </Field>
        </div>
      </SectionCard>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6 items-start">
      <SectionCard icon={IconClock} title={t('meetings.settings.section_agenda')}>
        <div className="flex items-center justify-between gap-4 rounded-lg border border-[var(--border-default)] bg-[var(--surface-subtle)] px-4 py-3 mb-4">
          <span className="text-sm font-medium text-[var(--text-primary)]">{t('meetings.settings.agenda_enabled')}</span>
          <Switch
            checked={form.agenda_request_enabled}
            onChange={(e) => set('agenda_request_enabled', e.target.checked)}
          />
        </div>
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <Field label={t('meetings.settings.agenda_lead')} hint={t('meetings.settings.hours_hint')}>
            <Input
              type="number"
              min={1}
              max={720}
              disabled={!form.agenda_request_enabled}
              value={String(form.agenda_request_lead_hours)}
              onChange={(e) => set('agenda_request_lead_hours', Number(e.target.value))}
            />
          </Field>
        </div>
      </SectionCard>

      <SectionCard icon={IconGavel} title={t('meetings.settings.section_decisions')}>
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <Field label={t('meetings.settings.decision_expiry')} hint={t('meetings.settings.days_hint')}>
            <Input
              type="number"
              min={1}
              max={365}
              value={String(form.decision_pending_expiry_days)}
              onChange={(e) => set('decision_pending_expiry_days', Number(e.target.value))}
            />
          </Field>
          <Field label={t('meetings.settings.recommendation_grace')} hint={t('meetings.settings.days_hint')}>
            <Input
              type="number"
              min={0}
              max={365}
              value={String(form.recommendation_overdue_grace_days)}
              onChange={(e) => set('recommendation_overdue_grace_days', Number(e.target.value))}
            />
          </Field>
        </div>
      </SectionCard>
      </div>

      <div className="flex justify-end">
        <Button onClick={handleSave} disabled={isSaving}>
          {isSaving ? t('common.loading') : t('common.save')}
        </Button>
      </div>
    </div>
  );
};

const MeetingSettingsPage: React.FC = () => {
  const { t } = useTranslation();

  return (
    <div className="space-y-6">
      <PageHeader
        title={t('meetings.settings.title')}
        subtitle={t('meetings.settings.subtitle')}
        icon={IconSettings}
      />

      <Tabs defaultValue="general">
        <TabsList>
          <TabsTrigger value="general" icon={<IconAdjustments className="h-4 w-4" />}>
            {t('meetings.settings.tab_general')}
          </TabsTrigger>
          <TabsTrigger value="categories" icon={<IconListTree className="h-4 w-4" />}>
            {t('meetings.settings.tab_categories')}
          </TabsTrigger>
        </TabsList>
        <TabsContent value="general">
          <GeneralSettings />
        </TabsContent>
        <TabsContent value="categories">
          <MeetingCategoriesSettings embedded />
        </TabsContent>
      </Tabs>
    </div>
  );
};

export default MeetingSettingsPage;
