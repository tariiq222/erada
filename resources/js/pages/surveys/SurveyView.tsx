import React, { useEffect, useState } from 'react';
import { useParams, Link } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { surveysApi } from '@entities/survey';
import { Button, Card, Badge, Breadcrumb, Skeleton, Modal, ModalBody, ModalFooter, PageHeader, StatusBadge, Checkbox } from '@shared/ui';
import { useToast } from '@shared/ui/Toast';
import { StatCard } from '@shared/ui';
import { RequiredIndicator } from '@shared/ui/RequiredIndicator';
import {IconEdit, IconSettings, IconSend, IconArchive, IconCopy, IconExternalLink, IconUsers, IconCalendar, IconClock, IconCircleCheck, IconFileText, IconClipboardList, IconChartBar, IconCircleX, IconAlertTriangle, IconWorld, IconLock, IconRepeat, IconPencil} from '@tabler/icons-react';
import { statusLabels, statusVariants, typeLabels, typeVariants } from './list';

interface SurveyField {
  id: number;
  field_key: string;
  label: string;
  type: string;
  is_required: boolean;
  order: number;
}

interface Survey {
  id: number;
  code: string;
  title: string;
  description: string | null;
  type: 'initial' | 'periodic';
  status: 'draft' | 'published' | 'closed' | 'archived';
  category: string | null;
  is_public: boolean;
  requires_auth: boolean;
  allow_multiple_responses: boolean;
  allow_edit_response: boolean;
  responses_count: number;
  fields_count: number;
  fields: SurveyField[];
  published_at: string | null;
  starts_at: string | null;
  ends_at: string | null;
  welcome_message: string | null;
  thank_you_message: string | null;
  consent_required: boolean;
  consent_text: string | null;
  created_at: string;
  public_url: string;
}

const fieldTypeLabels: Record<string, string> = {
  text: 'surveys.field_type_text',
  textarea: 'surveys.field_type_textarea',
  number: 'surveys.field_type_number',
  email: 'surveys.field_type_email',
  phone: 'surveys.field_type_phone',
  date: 'surveys.field_type_date',
  time: 'surveys.field_type_time',
  datetime: 'surveys.field_type_datetime',
  select: 'surveys.field_type_select',
  radio: 'surveys.field_type_radio',
  checkbox: 'surveys.field_type_checkbox',
  rating: 'surveys.field_type_rating',
  scale: 'surveys.field_type_scale',
  file: 'surveys.field_type_file',
  heading: 'surveys.field_type_heading',
  paragraph: 'surveys.field_type_paragraph',
};

const SurveyViewSkeleton: React.FC = () => (
  <div className="space-y-4">
    <Skeleton className="h-8 w-48" />
    <div className="flex items-center gap-3">
      <Skeleton className="h-8 w-64" />
      <Skeleton className="h-6 w-20 rounded-full" />
    </div>
    <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
      {[1, 2, 3, 4].map((i) => (
        <Skeleton key={i} className="h-24 rounded-xl" />
      ))}
    </div>
    <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
      <div className="lg:col-span-2 space-y-4">
        <Skeleton className="h-32 rounded-xl" />
        <Skeleton className="h-64 rounded-xl" />
      </div>
      <div className="space-y-4">
        <Skeleton className="h-40 rounded-xl" />
        <Skeleton className="h-32 rounded-xl" />
      </div>
    </div>
  </div>
);

interface PublishSettings {
  is_public: boolean;
  requires_auth: boolean;
  allow_multiple_responses: boolean;
  allow_edit_response: boolean;
}

const SurveyView: React.FC = () => {
  const { t } = useTranslation();
  const { id } = useParams<{ id: string }>();
  const [survey, setSurvey] = useState<Survey | null>(null);
  const [loading, setLoading] = useState(true);
  const [copying, setCopying] = useState(false);
  const [publishModalOpen, setPublishModalOpen] = useState(false);
  const [publishing, setPublishing] = useState(false);
  const [publishSettings, setPublishSettings] = useState<PublishSettings>({
    is_public: true,
    requires_auth: false,
    allow_multiple_responses: false,
    allow_edit_response: false,
  });
  const { showToast } = useToast();

  const fetchSurvey = async () => {
    try {
      const response = await surveysApi.getById(Number(id));
      setSurvey(response as Survey);
    } catch (error) {
      console.error('Failed to fetch survey:', error);
      showToast('error', t('surveys.load_error'));
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchSurvey();
  }, [id]);

  const openPublishModal = () => {
    if (!survey) return;
    // تعيين الإعدادات الحالية للاستبيان
    setPublishSettings({
      is_public: survey.is_public,
      requires_auth: survey.requires_auth,
      allow_multiple_responses: survey.allow_multiple_responses,
      allow_edit_response: survey.allow_edit_response,
    });
    setPublishModalOpen(true);
  };

  const handlePublish = async () => {
    if (!survey) return;
    setPublishing(true);
    try {
      // تحديث الإعدادات أولاً ثم النشر
      await surveysApi.update(survey.id, publishSettings);
      await surveysApi.publish(survey.id);
      showToast('success', t('surveys.publish_success'));
      setPublishModalOpen(false);
      fetchSurvey();
    } catch (error: unknown) {
      const err = error as { response?: { data?: { message?: string } } };
      const message = err.response?.data?.message || t('surveys.publish_error');
      showToast('error', message);
    } finally {
      setPublishing(false);
    }
  };

  const handleClose = async () => {
    if (!survey) return;
    try {
      await surveysApi.close(survey.id);
      showToast('success', t('surveys.close_success'));
      fetchSurvey();
    } catch (error) {
      showToast('error', t('surveys.close_error'));
    }
  };

  const handleCopyLink = async () => {
    if (!survey) return;
    setCopying(true);
    try {
      await navigator.clipboard.writeText(survey.public_url);
      showToast('success', t('surveys.link_copied'));
    } catch {
      showToast('error', t('surveys.link_copy_error'));
    } finally {
      setCopying(false);
    }
  };

  const formatDate = (date: string | null) => {
    if (!date) return '-';
    return new Date(date).toLocaleDateString('ar-EG-u-nu-latn', {
      year: 'numeric',
      month: 'long',
      day: 'numeric',
    });
  };

  if (loading) {
    return <SurveyViewSkeleton />;
  }

  if (!survey) {
    return (
      <div className="flex flex-col items-center justify-center min-h-[400px] gap-4">
        <IconClipboardList className="h-16 w-16 text-[var(--text-tertiary)]" />
        <p className="text-[var(--text-secondary)]">{t('surveys.not_found')}</p>
        <Link to="/surveys">
          <Button variant="secondary">{t('common.back_to_list')}</Button>
        </Link>
      </div>
    );
  }

  return (
    <div className="space-y-4">
      {/* Breadcrumb */}
      <Breadcrumb
        items={[
          { label: t('surveys.title'), href: '/surveys' },
          { label: survey.title },
        ]}
      />

      {/* Header */}
      <PageHeader
        title={survey.title}
        icon={IconClipboardList}
        iconTone="survey"
        status={
          <StatusBadge
            type="custom"
            status={survey.status}
            label={t(statusLabels[survey.status])}
            color={statusVariants[survey.status]}
          />
        }
        metadata={<span className="font-mono text-xs">{survey.code}</span>}
        actions={
          <>
            {survey.status === 'draft' && (
              <>
                <Link to={`/surveys/${survey.id}/edit`}>
                  <Button variant="outline" size="sm" leftIcon={<IconEdit className="h-4 w-4" />}>
                    {t('common.edit')}
                  </Button>
                </Link>
                <Link to={`/surveys/${survey.id}/builder`}>
                  <Button variant="secondary" size="sm" leftIcon={<IconSettings className="h-4 w-4" />}>
                    {t('surveys.build_fields')}
                  </Button>
                </Link>
                <Button size="sm" leftIcon={<IconSend className="h-4 w-4" />} onClick={openPublishModal}>
                  {t('surveys.publish')}
                </Button>
              </>
            )}
            {survey.status === 'published' && (
              <>
                <Button variant="secondary" size="sm" leftIcon={<IconCopy className="h-4 w-4" />} onClick={handleCopyLink} disabled={copying}>
                  {copying ? t('surveys.copying') : t('surveys.copy_link')}
                </Button>
                <a href={survey.public_url} target="_blank" rel="noopener noreferrer">
                  <Button variant="secondary" size="sm" leftIcon={<IconExternalLink className="h-4 w-4" />}>
                    {t('surveys.preview')}
                  </Button>
                </a>
                <Button variant="danger" size="sm" leftIcon={<IconArchive className="h-4 w-4" />} onClick={handleClose}>
                  {t('common.close')}
                </Button>
              </>
            )}
          </>
        }
      />

      {/* Info Bar with Tags */}
      <div className="flex flex-wrap items-center gap-2 text-sm">
        <Badge variant={typeVariants[survey.type]} size="sm">
          {t(typeLabels[survey.type])}
        </Badge>
        {survey.is_public && (
          <div className="inline-flex items-center gap-1 px-3 py-1 rounded-full bg-[var(--status-success-subtle)] text-[var(--status-success)] border border-[var(--status-success)]/20">
            <IconCircleCheck className="h-3.5 w-3.5" />
            <span className="font-medium">{t('surveys.public_link')}</span>
          </div>
        )}
        {survey.requires_auth && (
          <div className="inline-flex items-center gap-1 px-3 py-1 rounded-full bg-[var(--surface-muted)] text-[var(--text-secondary)] border border-[var(--border-default)]">
            <IconUsers className="h-3.5 w-3.5" />
            <span className="font-medium">{t('surveys.requires_auth')}</span>
          </div>
        )}
      </div>

      {/* Stats Cards */}
      <div className="grid grid-cols-2 md:grid-cols-4 gap-2 sm:gap-4">
        <StatCard
          label={t('surveys.responses')}
          value={survey.responses_count}
          icon={IconUsers}
          color="accent"
        />
        <StatCard
          label={t('surveys.fields')}
          value={survey.fields_count}
          icon={IconFileText}
          color="success"
        />
        <StatCard
          label={t('common.created_at')}
          value={formatDate(survey.created_at)}
          icon={IconCalendar}
          color="accent"
        />
        <StatCard
          label={t('surveys.published_at')}
          value={formatDate(survey.published_at)}
          icon={IconClock}
          color="warning"
        />
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {/* Main Content */}
        <div className="lg:col-span-2 space-y-6">
          {/* Description */}
          {survey.description && (
            <Card className="border border-[var(--border-default)] p-6">
              <h2 className="text-lg font-semibold text-[var(--text-primary)] mb-3">{t('common.description')}</h2>
              <p className="text-[var(--text-secondary)] whitespace-pre-wrap">{survey.description}</p>
            </Card>
          )}

          {/* Fields */}
          <Card className="border border-[var(--border-default)] p-6">
            <div className="flex items-center justify-between mb-4">
              <h2 className="text-lg font-semibold text-[var(--text-primary)]">
                {t('surveys.fields')} ({survey.fields?.length || 0})
              </h2>
              {survey.status === 'draft' && (
                <Link to={`/surveys/${survey.id}/builder`}>
                  <Button variant="secondary" size="sm" leftIcon={<IconSettings className="h-4 w-4" />}>
                    {t('surveys.edit_fields')}
                  </Button>
                </Link>
              )}
            </div>

            {survey.fields && survey.fields.length > 0 ? (
              <div className="space-y-3">
                {survey.fields
                  .sort((a, b) => a.order - b.order)
                  .map((field, index) => (
                    <div
                      key={field.id}
                      className="flex items-center gap-4 p-3 rounded-lg bg-[var(--bg-secondary)] border border-[var(--border-default)]"
                    >
                      <span className="w-8 h-8 flex items-center justify-center rounded-full bg-[var(--accent-subtle)] text-[var(--accent-default)] text-sm font-medium">
                        {index + 1}
                      </span>
                      <div className="flex-1">
                        <div className="flex items-center gap-2">
                          <span className="font-medium text-[var(--text-primary)]">
                            {field.label}
                            {field.is_required && <RequiredIndicator className="mr-1" />}
                          </span>
                        </div>
                        <div className="flex items-center gap-2 mt-1">
                          <code className="text-xs bg-[var(--bg-tertiary)] px-1 py-0 rounded font-mono">
                            {field.field_key}
                          </code>
                          <span className="text-xs text-[var(--text-secondary)]">
                            {t(fieldTypeLabels[field.type]) || field.type}
                          </span>
                        </div>
                      </div>
                    </div>
                  ))}
              </div>
            ) : (
              <div className="text-center py-8 text-[var(--text-secondary)]">
                <IconFileText className="w-12 h-12 mx-auto mb-3 opacity-50" />
                <p>{t('surveys.no_fields_yet')}</p>
                {survey.status === 'draft' && (
                  <Link to={`/surveys/${survey.id}/builder`}>
                    <Button variant="secondary" size="sm" className="mt-4">
                      {t('surveys.add_fields')}
                    </Button>
                  </Link>
                )}
              </div>
            )}
          </Card>

          {/* Responses Link */}
          {survey.status !== 'draft' && survey.responses_count > 0 && (
            <Card className="border border-[var(--border-default)] p-6">
              <div className="flex items-center justify-between">
                <div>
                  <h2 className="text-lg font-semibold text-[var(--text-primary)]">{t('surveys.responses')}</h2>
                  <p className="text-[var(--text-secondary)]">
                    {survey.responses_count} {t('surveys.recorded_responses')}
                  </p>
                </div>
                <Link to={`/surveys/${survey.id}/responses`}>
                  <Button leftIcon={<IconChartBar className="h-4 w-4" />}>{t('surveys.view_responses')}</Button>
                </Link>
              </div>
            </Card>
          )}
        </div>

        {/* Sidebar */}
        <div className="space-y-6">
          {/* IconSettings */}
          <Card className="border border-[var(--border-default)] p-6">
            <h2 className="text-lg font-semibold text-[var(--text-primary)] mb-4">{t('common.settings')}</h2>
            <div className="space-y-3">
              <div className="flex items-center justify-between">
                <span className="text-[var(--text-secondary)]">{t('surveys.multiple_responses')}</span>
                <span className="text-[var(--text-primary)]">
                  {survey.allow_multiple_responses ? (
                    <IconCircleCheck className="w-5 h-5 text-[var(--status-success)]" />
                  ) : (
                    <IconCircleX className="w-5 h-5 text-[var(--text-tertiary)]" />
                  )}
                </span>
              </div>
              <div className="flex items-center justify-between">
                <span className="text-[var(--text-secondary)]">{t('surveys.edit_response')}</span>
                <span className="text-[var(--text-primary)]">
                  {survey.allow_edit_response ? (
                    <IconCircleCheck className="w-5 h-5 text-[var(--status-success)]" />
                  ) : (
                    <IconCircleX className="w-5 h-5 text-[var(--text-tertiary)]" />
                  )}
                </span>
              </div>
              <div className="flex items-center justify-between">
                <span className="text-[var(--text-secondary)]">{t('surveys.consent_required')}</span>
                <span className="text-[var(--text-primary)]">
                  {survey.consent_required ? (
                    <IconCircleCheck className="w-5 h-5 text-[var(--status-success)]" />
                  ) : (
                    <IconCircleX className="w-5 h-5 text-[var(--text-tertiary)]" />
                  )}
                </span>
              </div>
            </div>
          </Card>

          {/* Time Period */}
          <Card className="border border-[var(--border-default)] p-6">
            <h2 className="text-lg font-semibold text-[var(--text-primary)] mb-4">{t('surveys.time_period')}</h2>
            <div className="space-y-3">
              <div className="flex items-center justify-between">
                <span className="text-[var(--text-secondary)]">{t('surveys.start')}</span>
                <span className="text-[var(--text-primary)]">{formatDate(survey.starts_at)}</span>
              </div>
              <div className="flex items-center justify-between">
                <span className="text-[var(--text-secondary)]">{t('surveys.end')}</span>
                <span className="text-[var(--text-primary)]">{formatDate(survey.ends_at)}</span>
              </div>
            </div>
          </Card>

          {/* Public Link */}
          {survey.status === 'published' && survey.is_public && (
            <Card className="border border-[var(--border-default)] p-6">
              <h2 className="text-lg font-semibold text-[var(--text-primary)] mb-4">{t('surveys.survey_link')}</h2>
              <div className="flex items-center gap-2">
                <input
                  type="text"
                  value={survey.public_url}
                  readOnly
                  className="flex-1 text-sm bg-[var(--bg-secondary)] border border-[var(--border-primary)] rounded-lg px-3 py-2"
                />
                <Button variant="secondary" size="sm" onClick={handleCopyLink}>
                  <IconCopy className="w-4 h-4" />
                </Button>
              </div>
            </Card>
          )}
        </div>
      </div>

      {/* Publish Confirmation Modal */}
      <Modal
        open={publishModalOpen}
        onClose={() => setPublishModalOpen(false)}
        title={t('surveys.publish_survey')}
        size="md"
      >
        <ModalBody>
          <div className="space-y-6">
            {/* تحذيرات */}
            <div className="rounded-lg bg-[var(--status-warning-subtle)] border border-[var(--status-warning)]/30 p-4">
              <div className="flex items-start gap-3">
                <IconAlertTriangle className="h-5 w-5 text-[var(--status-warning)] shrink-0 mt-0" />
                <div>
                  <h4 className="font-semibold text-[var(--text-primary)] mb-1">{t('surveys.important_notice')}</h4>
                  <ul className="text-sm text-[var(--text-secondary)] space-y-1 list-disc list-inside">
                    <li>{t('surveys.publish_warning_1')}</li>
                    <li>{t('surveys.publish_warning_2')}</li>
                    <li>{t('surveys.publish_warning_3')}</li>
                  </ul>
                </div>
              </div>
            </div>

            {/* إعدادات النشر */}
            <div>
              <h4 className="font-semibold text-[var(--text-primary)] mb-3">{t('surveys.publish_settings')}</h4>
              <div className="space-y-4">
                {/* الوصول العام */}
                <div className="p-3 rounded-lg bg-[var(--bg-secondary)] border border-[var(--border-default)] hover:bg-[var(--surface-muted)] transition-colors">
                  <div className="flex items-start gap-3">
                    <IconWorld className="h-5 w-5 text-[var(--text-secondary)] mt-0.5 shrink-0" />
                    <Checkbox
                      label={t('surveys.public_link')}
                      description={t('surveys.public_link_desc')}
                      checked={publishSettings.is_public}
                      onChange={(e) => setPublishSettings({ ...publishSettings, is_public: e.target.checked })}
                    />
                  </div>
                </div>

                {/* يتطلب تسجيل دخول */}
                <div className="p-3 rounded-lg bg-[var(--bg-secondary)] border border-[var(--border-default)] hover:bg-[var(--surface-muted)] transition-colors">
                  <div className="flex items-start gap-3">
                    <IconLock className="h-5 w-5 text-[var(--text-secondary)] mt-0.5 shrink-0" />
                    <Checkbox
                      label={t('surveys.requires_auth')}
                      description={t('surveys.requires_auth_modal_desc')}
                      checked={publishSettings.requires_auth}
                      onChange={(e) => setPublishSettings({ ...publishSettings, requires_auth: e.target.checked })}
                    />
                  </div>
                </div>

                {/* السماح بإجابات متعددة */}
                <div className="p-3 rounded-lg bg-[var(--bg-secondary)] border border-[var(--border-default)] hover:bg-[var(--surface-muted)] transition-colors">
                  <div className="flex items-start gap-3">
                    <IconRepeat className="h-5 w-5 text-[var(--text-secondary)] mt-0.5 shrink-0" />
                    <Checkbox
                      label={t('surveys.multiple_responses')}
                      description={t('surveys.multiple_responses_desc')}
                      checked={publishSettings.allow_multiple_responses}
                      onChange={(e) => setPublishSettings({ ...publishSettings, allow_multiple_responses: e.target.checked })}
                    />
                  </div>
                </div>

                {/* السماح بتعديل الإجابة */}
                <div className="p-3 rounded-lg bg-[var(--bg-secondary)] border border-[var(--border-default)] hover:bg-[var(--surface-muted)] transition-colors">
                  <div className="flex items-start gap-3">
                    <IconPencil className="h-5 w-5 text-[var(--text-secondary)] mt-0.5 shrink-0" />
                    <Checkbox
                      label={t('surveys.edit_response')}
                      description={t('surveys.edit_response_desc')}
                      checked={publishSettings.allow_edit_response}
                      onChange={(e) => setPublishSettings({ ...publishSettings, allow_edit_response: e.target.checked })}
                    />
                  </div>
                </div>
              </div>
            </div>

            {/* معلومات إضافية */}
            <div className="text-sm text-[var(--text-secondary)] p-3 rounded-lg bg-[var(--bg-secondary)] border border-[var(--border-default)]">
              <div className="flex items-center gap-2 mb-2">
                <IconFileText className="h-4 w-4" />
                <span className="font-medium">{t('surveys.survey_info')}</span>
              </div>
              <div className="grid grid-cols-2 gap-2 text-xs">
                <span>{t('surveys.fields_count')}: {survey?.fields_count || 0}</span>
                <span>{t('surveys.type')}: {survey ? t(typeLabels[survey.type]) : '-'}</span>
              </div>
            </div>
          </div>
        </ModalBody>
        <ModalFooter>
          <Button variant="secondary" onClick={() => setPublishModalOpen(false)}>
            {t('common.cancel')}
          </Button>
          <Button
            onClick={handlePublish}
            loading={publishing}
            leftIcon={<IconSend className="h-4 w-4" />}
          >
            {t('surveys.confirm_publish')}
          </Button>
        </ModalFooter>
      </Modal>
    </div>
  );
};

export default SurveyView;
