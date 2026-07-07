import React, { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import {
  IconSettings,
  IconAdjustments,
  IconPaperclip,
  IconSitemap,
} from '@tabler/icons-react';
import {
  Button,
  Card,
  Input,
  Select,
  Skeleton,
  Switch,
  Tabs,
  TabsContent,
  TabsList,
  TabsTrigger,
  PageHeader,
  getProjectStatusLabel,
  type ProjectStatus,
} from '@shared/ui';
import { useToast } from '@shared/ui/Toast';
import {
  projectsApi,
  type ProjectSettings as ProjectSettingsData,
  type ProjectSettingsPayload,
} from '@entities/project';
import GoverningDepartmentsSection from './components/GoverningDepartmentsSection';

const PROJECT_STATUSES: ProjectStatus[] = [
  'draft',
  'planning',
  'in_progress',
  'on_hold',
  'completed',
  'cancelled',
];

const ALLOWED_FILE_TYPES = [
  'pdf',
  'jpg',
  'jpeg',
  'png',
  'doc',
  'docx',
  'xls',
  'xlsx',
  'txt',
  'gif',
] as const;

const getData = <T,>(response: unknown): T | null => {
  if (!response || typeof response !== 'object') return null;
  const data = (response as { data?: unknown }).data;
  return (data ?? response) as T;
};

const getErrorMessage = (error: unknown, fallback: string): string => {
  if (error && typeof error === 'object') {
    const message = (error as { message?: unknown }).message;
    if (typeof message === 'string' && message.trim()) return message;
    const responseMessage = (error as {
      response?: { data?: { message?: unknown } };
    }).response?.data?.message;
    if (typeof responseMessage === 'string' && responseMessage.trim()) return responseMessage;
  }
  return fallback;
};

const Field: React.FC<{ label: string; hint?: string; children: React.ReactNode }> = ({
  label,
  hint,
  children,
}) => (
  <div className="space-y-1.5">
    <label className="block text-sm font-medium text-[var(--text-primary)]">{label}</label>
    {children}
    {hint && <p className="text-xs text-[var(--text-tertiary)]">{hint}</p>}
  </div>
);

const SectionCard: React.FC<{
  icon: React.ElementType;
  title: string;
  children: React.ReactNode;
}> = ({ icon: Icon, title, children }) => (
  <Card className="p-4 sm:p-6 border border-[var(--border-default)]">
    <div className="flex items-center gap-2 mb-4">
      <Icon className="h-4 w-4 text-[var(--text-tertiary)]" />
      <h3 className="text-base font-semibold text-[var(--text-primary)]">{title}</h3>
    </div>
    {children}
  </Card>
);

const ProjectSettings: React.FC = () => {
  const { t } = useTranslation();
  const { showToast } = useToast();

  const [form, setForm] = useState<ProjectSettingsData | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [isSaving, setIsSaving] = useState(false);
  const [tab, setTab] = useState('general');

  const loadSettings = async () => {
    setIsLoading(true);
    try {
      const response = getData<ProjectSettingsData>(await projectsApi.getSettings());
      if (response) {
        setForm(response);
      }
    } catch (error) {
      showToast('error', getErrorMessage(error, t('projects.settings.load_failed')));
    } finally {
      setIsLoading(false);
    }
  };

  useEffect(() => {
    loadSettings();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const setProjectField = <K extends keyof ProjectSettingsData['project']>(
    key: K,
    value: ProjectSettingsData['project'][K],
  ) => {
    setForm((prev: ProjectSettingsData | null) =>
      prev ? { ...prev, project: { ...prev.project, [key]: value } } : prev,
    );
  };

  const setAttachmentsField = <K extends keyof ProjectSettingsData['attachments']>(
    key: K,
    value: ProjectSettingsData['attachments'][K],
  ) => {
    setForm((prev: ProjectSettingsData | null) =>
      prev ? { ...prev, attachments: { ...prev.attachments, [key]: value } } : prev,
    );
  };

  const toggleAllowedType = (type: string, enabled: boolean) => {
    setForm((prev: ProjectSettingsData | null) => {
      if (!prev) return prev;
      const current = prev.attachments.allowed_types ?? [];
      const next = enabled
        ? Array.from(new Set([...current, type]))
        : current.filter((item: string) => item !== type);
      return {
        ...prev,
        attachments: { ...prev.attachments, allowed_types: next },
      };
    });
  };

  const handleSave = async () => {
    if (!form) return;

    // Light client-side guard: server is authoritative, but block obvious mistakes early.
    const size = form.attachments.max_size_mb;
    if (!Number.isFinite(size) || size < 1 || size > 100) {
      showToast('error', t('projects.settings.max_size_hint'));
      return;
    }

    const payload: ProjectSettingsPayload = {
      project: { default_status: form.project.default_status },
      attachments: {
        max_size_mb: size,
        allowed_types: form.attachments.allowed_types ?? [],
      },
    };

    setIsSaving(true);
    try {
      const response = getData<ProjectSettingsData>(await projectsApi.updateSettings(payload));
      if (response) {
        setForm(response);
      }
      showToast('success', t('projects.settings.saved'));
    } catch (error) {
      showToast('error', getErrorMessage(error, t('projects.settings.save_failed')));
    } finally {
      setIsSaving(false);
    }
  };

  const loadingCard = (
    <Card className="p-4 sm:p-6 space-y-4">
      {[...Array(3)].map((_, i) => (
        <Skeleton key={i} className="h-10 w-full" />
      ))}
    </Card>
  );

  return (
    <div className="space-y-6 max-w-5xl mx-auto">
      <PageHeader
        title={t('projects.settings.title')}
        subtitle={t('projects.settings.subtitle')}
        icon={IconSettings}
        iconTone="project"
      />

      <Tabs value={tab} onValueChange={setTab} defaultValue="general">
        <TabsList>
          <TabsTrigger value="general" icon={<IconAdjustments className="h-4 w-4" />}>
            {t('projects.settings.section_general')}
          </TabsTrigger>
          <TabsTrigger value="attachments" icon={<IconPaperclip className="h-4 w-4" />}>
            {t('projects.settings.section_attachments')}
          </TabsTrigger>
          <TabsTrigger value="governing" icon={<IconSitemap className="h-4 w-4" />}>
            {t('projects.governing_departments')}
          </TabsTrigger>
        </TabsList>

        <TabsContent value="general">
          {isLoading || !form ? (
            loadingCard
          ) : (
            <SectionCard
              icon={IconAdjustments}
              title={t('projects.settings.section_general')}
            >
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <Field label={t('projects.settings.default_status')}>
                  <Select
                    value={form.project.default_status}
                    onChange={(e) =>
                      setProjectField('default_status', e.target.value as ProjectSettingsData['project']['default_status'])
                    }
                    options={PROJECT_STATUSES.map((s) => ({
                      value: s,
                      label: getProjectStatusLabel(s, t),
                    }))}
                  />
                </Field>
              </div>
            </SectionCard>
          )}
        </TabsContent>

        <TabsContent value="attachments">
          {isLoading || !form ? (
            loadingCard
          ) : (
            <SectionCard
              icon={IconPaperclip}
              title={t('projects.settings.section_attachments')}
            >
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <Field
                  label={t('projects.settings.max_size')}
                  hint={t('projects.settings.max_size_hint')}
                >
                  <Input
                    type="number"
                    min={1}
                    max={100}
                    value={String(form.attachments.max_size_mb)}
                    onChange={(e) =>
                      setAttachmentsField('max_size_mb', Number(e.target.value))
                    }
                  />
                </Field>
              </div>
              <div className="mt-4">
                <Field
                  label={t('projects.settings.allowed_types')}
                  hint={t('projects.settings.allowed_types_hint')}
                >
                  <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-5 gap-3">
                    {ALLOWED_FILE_TYPES.map((type) => {
                      const checked = form.attachments.allowed_types.includes(type);
                      return (
                        <div
                          key={type}
                          className="flex items-center justify-between gap-3 rounded-lg border border-[var(--border-default)] bg-[var(--surface-subtle)] px-3 py-2"
                        >
                          <span className="text-sm font-medium text-[var(--text-primary)] uppercase">
                            {type}
                          </span>
                          <Switch
                            checked={checked}
                            onChange={(e) => toggleAllowedType(type, e.target.checked)}
                            size="sm"
                          />
                        </div>
                      );
                    })}
                  </div>
                </Field>
              </div>
            </SectionCard>
          )}
        </TabsContent>

        <TabsContent value="governing">
          <GoverningDepartmentsSection />
        </TabsContent>
      </Tabs>

      {tab !== 'governing' && !isLoading && form && (
        <div className="flex justify-end">
          <Button onClick={handleSave} loading={isSaving}>
            {t('projects.settings.save')}
          </Button>
        </div>
      )}
    </div>
  );
};

export default ProjectSettings;
