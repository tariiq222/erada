import React, { useEffect, useState } from 'react';
import { useParams, useNavigate, Link } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { surveysApi } from '@entities/survey';
import { Button, Card, Input, Select, Breadcrumb, Skeleton, PageHeader, Textarea, Checkbox } from '@shared/ui';
import { RequiredIndicator } from '@shared/ui/RequiredIndicator';
import { useToast } from '@shared/ui/Toast';
import {IconDeviceFloppy, IconClipboardList} from '@tabler/icons-react';

interface SurveyFormData {
  title: string;
  description: string;
  type: 'initial' | 'periodic';
  category: string;
  is_public: boolean;
  requires_auth: boolean;
  allow_multiple_responses: boolean;
  allow_edit_response: boolean;
  starts_at: string;
  ends_at: string;
  consent_required: boolean;
  consent_text: string;
  welcome_message: string;
  thank_you_message: string;
}

const initialFormData: SurveyFormData = {
  title: '',
  description: '',
  type: 'initial',
  category: '',
  is_public: true,
  requires_auth: false,
  allow_multiple_responses: false,
  allow_edit_response: false,
  starts_at: '',
  ends_at: '',
  consent_required: false,
  consent_text: '',
  welcome_message: '',
  thank_you_message: '',
};

const SurveyForm: React.FC = () => {
  const { t } = useTranslation();
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const isEditing = Boolean(id);
  const [formData, setFormData] = useState<SurveyFormData>(initialFormData);
  const [loading, setLoading] = useState(false);
  const [fetching, setFetching] = useState(isEditing);
  const { showToast } = useToast();

  useEffect(() => {
    if (isEditing) {
      fetchSurvey();
    }
  }, [id]);

  const fetchSurvey = async () => {
    try {
      const response = await surveysApi.getById(Number(id));
      const survey = response as any;
      setFormData({
        title: survey.title || '',
        description: survey.description || '',
        type: survey.type || 'initial',
        category: survey.category || '',
        is_public: survey.is_public ?? true,
        requires_auth: survey.requires_auth ?? false,
        allow_multiple_responses: survey.allow_multiple_responses ?? false,
        allow_edit_response: survey.allow_edit_response ?? false,
        starts_at: survey.starts_at ? survey.starts_at.slice(0, 16) : '',
        ends_at: survey.ends_at ? survey.ends_at.slice(0, 16) : '',
        consent_required: survey.consent_required ?? false,
        consent_text: survey.consent_text || '',
        welcome_message: survey.welcome_message || '',
        thank_you_message: survey.thank_you_message || '',
      });
    } catch (error) {
      console.error('Failed to fetch survey:', error);
      showToast('error', t('surveys.load_error'));
    } finally {
      setFetching(false);
    }
  };

  const handleChange = (
    e: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement>
  ) => {
    const { name, value, type } = e.target;
    const checked = (e.target as HTMLInputElement).checked;
    setFormData((prev) => ({
      ...prev,
      [name]: type === 'checkbox' ? checked : value,
    }));
  };

  const handleSelectChange = (name: keyof SurveyFormData) => (e: { target: { value: string } }) => {
    setFormData((prev) => ({
      ...prev,
      [name]: e.target.value,
    }));
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setLoading(true);

    try {
      const data = {
        ...formData,
        starts_at: formData.starts_at || null,
        ends_at: formData.ends_at || null,
      };

      if (isEditing) {
        await surveysApi.update(Number(id), data);
        showToast('success', t('surveys.update_success'));
      } else {
        const response = (await surveysApi.create(data)) as { data: { id: number } };
        showToast('success', t('surveys.create_success'));
        navigate(`/surveys/${response.data.id}/builder`);
        return;
      }
      navigate(`/surveys/${id}`);
    } catch (error: any) {
      const message = error.response?.data?.message || t('common.save_error');
      showToast('error', message);
    } finally {
      setLoading(false);
    }
  };

  if (fetching) {
    return (
      <div className="space-y-4 sm:space-y-6">
        <Skeleton className="h-6 w-48" />
        <div className="flex items-center gap-3">
          <Skeleton className="h-10 w-10 rounded-lg" />
          <div className="space-y-2">
            <Skeleton className="h-5 w-40" />
            <Skeleton className="h-4 w-56" />
          </div>
        </div>
        <Card className="p-6">
          <Skeleton className="h-6 w-36 mb-4" />
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div className="md:col-span-2">
              <Skeleton className="h-4 w-28 mb-1" />
              <Skeleton className="h-10 w-full rounded-lg" />
            </div>
            <div>
              <Skeleton className="h-4 w-24 mb-1" />
              <Skeleton className="h-10 w-full rounded-lg" />
            </div>
            <div>
              <Skeleton className="h-4 w-20 mb-1" />
              <Skeleton className="h-10 w-full rounded-lg" />
            </div>
            <div className="md:col-span-2">
              <Skeleton className="h-4 w-16 mb-1" />
              <Skeleton className="h-24 w-full rounded-lg" />
            </div>
          </div>
        </Card>
        <Card className="p-6">
          <Skeleton className="h-6 w-32 mb-4" />
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            {[1, 2, 3, 4].map((i) => (
              <Skeleton key={i} className="h-20 w-full rounded-lg" />
            ))}
          </div>
        </Card>
      </div>
    );
  }

  return (
    <div className="space-y-4 sm:space-y-6">
      {/* Breadcrumb */}
      <Breadcrumb
        items={[
          { label: t('surveys.title'), href: '/surveys' },
          { label: isEditing ? t('surveys.edit') : t('surveys.new') },
        ]}
      />

      <PageHeader
        title={isEditing ? t('surveys.edit') : t('surveys.new')}
        subtitle={isEditing ? t('surveys.edit_subtitle') : t('surveys.new_subtitle')}
        icon={IconClipboardList}
        iconTone="survey"
      />

      <form onSubmit={handleSubmit} className="space-y-6">
        {/* Basic Info */}
        <Card className="p-6">
          <h2 className="text-lg font-semibold text-[var(--text-primary)] mb-4">
            {t('surveys.basic_info')}
          </h2>
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div className="md:col-span-2">
              <label className="block text-sm font-medium text-[var(--text-primary)] mb-1">
                {t('surveys.survey_title')} <RequiredIndicator />
              </label>
              <Input
                name="title"
                value={formData.title}
                onChange={handleChange}
                placeholder={t('surveys.survey_title_placeholder')}
                required
              />
            </div>

            <div>
              <label className="block text-sm font-medium text-[var(--text-primary)] mb-1">
                {t('surveys.survey_type')} <RequiredIndicator />
              </label>
              <Select
                name="type"
                value={formData.type}
                onChange={handleSelectChange('type')}
                options={[
                  { value: 'initial', label: t('surveys.type_initial_desc') },
                  { value: 'periodic', label: t('surveys.type_periodic_desc') },
                ]}
              />
              <p className="text-xs text-[var(--text-secondary)] mt-1">
                {formData.type === 'initial'
                  ? t('surveys.type_initial_hint')
                  : t('surveys.type_periodic_hint')}
              </p>
            </div>

            <div>
              <label className="block text-sm font-medium text-[var(--text-primary)] mb-1">
                {t('surveys.category')}
              </label>
              <Select
                name="category"
                value={formData.category}
                onChange={handleSelectChange('category')}
                options={[
                  { value: '', label: t('surveys.no_category') },
                  { value: 'kpi', label: t('surveys.category_kpi') },
                  { value: 'satisfaction', label: t('surveys.category_satisfaction') },
                  { value: 'needs', label: t('surveys.category_needs') },
                  { value: 'report', label: t('surveys.category_report') },
                ]}
              />
            </div>

            <div className="md:col-span-2">
              <Textarea
                name="description"
                value={formData.description}
                onChange={handleChange}
                rows={3}
                label={t('common.description')}
                placeholder={t('surveys.description_placeholder')}
              />
            </div>
          </div>
        </Card>

        {/* Access Settings */}
        <Card className="p-6">
          <h2 className="text-lg font-semibold text-[var(--text-primary)] mb-4">{t('surveys.access_settings')}</h2>
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div
              className={`flex items-start gap-3 p-4 rounded-lg border transition-colors ${
                formData.is_public
                  ? 'border-[var(--accent-default)] bg-[var(--accent-muted)]'
                  : 'border-[var(--border-default)] hover:border-[var(--border-strong)] hover:bg-[var(--surface-subtle)]'
              }`}
            >
              <Checkbox
                name="is_public"
                checked={formData.is_public}
                onChange={handleChange}
                label={t('surveys.public_link')}
                description={t('surveys.public_link_desc')}
              />
            </div>

            <div
              className={`flex items-start gap-3 p-4 rounded-lg border transition-colors ${
                formData.requires_auth
                  ? 'border-[var(--accent-default)] bg-[var(--accent-muted)]'
                  : 'border-[var(--border-default)] hover:border-[var(--border-strong)] hover:bg-[var(--surface-subtle)]'
              }`}
            >
              <Checkbox
                name="requires_auth"
                checked={formData.requires_auth}
                onChange={handleChange}
                label={t('surveys.requires_auth')}
                description={t('surveys.requires_auth_desc')}
              />
            </div>

            <div
              className={`flex items-start gap-3 p-4 rounded-lg border transition-colors ${
                formData.allow_multiple_responses
                  ? 'border-[var(--accent-default)] bg-[var(--accent-muted)]'
                  : 'border-[var(--border-default)] hover:border-[var(--border-strong)] hover:bg-[var(--surface-subtle)]'
              }`}
            >
              <Checkbox
                name="allow_multiple_responses"
                checked={formData.allow_multiple_responses}
                onChange={handleChange}
                label={t('surveys.multiple_responses')}
                description={t('surveys.multiple_responses_desc')}
              />
            </div>

            <div
              className={`flex items-start gap-3 p-4 rounded-lg border transition-colors ${
                formData.allow_edit_response
                  ? 'border-[var(--accent-default)] bg-[var(--accent-muted)]'
                  : 'border-[var(--border-default)] hover:border-[var(--border-strong)] hover:bg-[var(--surface-subtle)]'
              }`}
            >
              <Checkbox
                name="allow_edit_response"
                checked={formData.allow_edit_response}
                onChange={handleChange}
                label={t('surveys.edit_response')}
                description={t('surveys.edit_response_desc')}
              />
            </div>
          </div>
        </Card>

        {/* Time Period */}
        <Card className="p-6">
          <h2 className="text-lg font-semibold text-[var(--text-primary)] mb-4">{t('surveys.time_period')}</h2>
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label className="block text-sm font-medium text-[var(--text-primary)] mb-1">
                {t('surveys.start_date')}
              </label>
              <Input
                type="datetime-local"
                name="starts_at"
                value={formData.starts_at}
                onChange={handleChange}
              />
              <p className="text-xs text-[var(--text-secondary)] mt-1">
                {t('surveys.start_date_hint')}
              </p>
            </div>

            <div>
              <label className="block text-sm font-medium text-[var(--text-primary)] mb-1">
                {t('surveys.end_date')}
              </label>
              <Input
                type="datetime-local"
                name="ends_at"
                value={formData.ends_at}
                onChange={handleChange}
              />
              <p className="text-xs text-[var(--text-secondary)] mt-1">
                {t('surveys.end_date_hint')}
              </p>
            </div>
          </div>
        </Card>

        {/* Consent */}
        <Card className="p-6">
          <h2 className="text-lg font-semibold text-[var(--text-primary)] mb-4">{t('surveys.consent')}</h2>
          <div className="space-y-4">
            <Checkbox
              name="consent_required"
              checked={formData.consent_required}
              onChange={handleChange}
              label={t('surveys.consent_required')}
            />

            {formData.consent_required && (
              <div>
                <Textarea
                  name="consent_text"
                  value={formData.consent_text}
                  onChange={handleChange}
                  rows={3}
                  label={t('surveys.consent_text')}
                  placeholder={t('surveys.consent_text_placeholder')}
                />
              </div>
            )}
          </div>
        </Card>

        {/* Messages */}
        <Card className="p-6">
          <h2 className="text-lg font-semibold text-[var(--text-primary)] mb-4">{t('surveys.messages')}</h2>
          <div className="space-y-4">
            <div>
              <Textarea
                name="welcome_message"
                value={formData.welcome_message}
                onChange={handleChange}
                rows={2}
                label={t('surveys.welcome_message')}
                placeholder={t('surveys.welcome_message_placeholder')}
              />
            </div>

            <div>
              <Textarea
                name="thank_you_message"
                value={formData.thank_you_message}
                onChange={handleChange}
                rows={2}
                label={t('surveys.thank_you_message')}
                placeholder={t('surveys.thank_you_message_placeholder')}
              />
            </div>
          </div>
        </Card>

        {/* Actions */}
        <div className="flex items-center justify-end gap-3">
          <Link to={isEditing ? `/surveys/${id}` : '/surveys'}>
            <Button type="button" variant="outline">
              {t('common.cancel')}
            </Button>
          </Link>
          <Button type="submit" leftIcon={<IconDeviceFloppy className="h-4 w-4" />} disabled={loading}>
            {loading ? t('common.saving') : isEditing ? t('common.save_changes') : t('surveys.create_and_continue')}
          </Button>
        </div>
      </form>
    </div>
  );
};

export default SurveyForm;
