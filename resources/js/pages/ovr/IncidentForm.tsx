import React, { useEffect, useMemo, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { IconAlertTriangle, IconDeviceFloppy, IconX } from '@tabler/icons-react';
import {
  Button,
  Card,
  Input,
  Select,
  Textarea,
  Switch,
  DatePicker,
  Breadcrumb,
  PageHeader,
  FormSection,
} from '@shared/ui';
import { useToast } from '@shared/ui/Toast';
import { SkipToMain } from '@shared/ui/SkipToMain';
import { incidentsApi, incidentCategoriesApi } from '@entities/incident';
import type { Category, IncidentFormData } from './components';

const EMPTY_FORM: IncidentFormData = {
  incident_type_id: '',
  reportable_incident_type_id: '',
  description: '',
  incident_datetime: '',
  is_patient_related: false,
  patient_file_number: '',
  patient_name: '',
  severity_level: 'medium',
  actions_taken: '',
  contributing_factors: [],
  immediate_action_required: false,
  is_confidential: false,
};

const IncidentForm: React.FC = () => {
  const { t } = useTranslation();
  const { reportNumber } = useParams<{ reportNumber: string }>();
  const navigate = useNavigate();
  const { showToast } = useToast();
  const isEdit = !!reportNumber;

  const [loading, setLoading] = useState(isEdit);
  const [saving, setSaving] = useState(false);
  const [categories, setCategories] = useState<Category[]>([]);
  const [formData, setFormData] = useState<IncidentFormData>(EMPTY_FORM);
  const [incidentDate, setIncidentDate] = useState('');
  const [incidentTime, setIncidentTime] = useState('');
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});

  const getFieldError = (key: string): string | undefined => {
    const value = fieldErrors[key];
    if (Array.isArray(value)) return value[0];
    return value;
  };

  const selectedCategory = useMemo(() => {
    if (!formData.incident_type_id) return null;
    return categories.find((c) => c.id.toString() === formData.incident_type_id) || null;
  }, [formData.incident_type_id, categories]);

  useEffect(() => {
    fetchCategories();
    if (isEdit) {
      fetchIncident();
    } else {
      const now = new Date();
      setIncidentDate(now.toISOString().split('T')[0]);
      setIncidentTime(now.toTimeString().slice(0, 5));
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [reportNumber]);

  const fetchCategories = async () => {
    try {
      const res = (await incidentCategoriesApi.getAll()) as Category[] | { data: Category[] };
      setCategories(Array.isArray(res) ? res : (res?.data ?? []));
    } catch (error) {
      console.warn('Failed to load categories:', error);
    }
  };

  const fetchIncident = async () => {
    setLoading(true);
    try {
      const incident = (await incidentsApi.getOne(reportNumber!)) as any;
      setFormData({
        incident_type_id: incident.incident_type?.id?.toString() || '',
        reportable_incident_type_id: incident.reportable_incident_type?.id?.toString() || '',
        description: incident.description || '',
        incident_datetime: incident.incident_datetime || '',
        is_patient_related: incident.is_patient_related,
        patient_file_number: incident.patient_file_number || '',
        patient_name: incident.patient_name || '',
        severity_level: incident.severity_level,
        actions_taken: incident.actions_taken || '',
        contributing_factors: Array.isArray(incident.contributing_factors)
          ? incident.contributing_factors
          : [],
        immediate_action_required: incident.immediate_action_required,
        is_confidential: incident.is_confidential,
      });
      if (incident.incident_datetime) {
        const d = new Date(incident.incident_datetime);
        setIncidentDate(d.toISOString().split('T')[0]);
        setIncidentTime(d.toTimeString().slice(0, 5));
      }
    } catch {
      showToast('error', t('ovr.load_error'));
      navigate('/ovr/incidents');
    } finally {
      setLoading(false);
    }
  };

  const buildDatetime = (date: string, time: string): string => {
    if (!date) return '';
    return `${date}T${time || '00:00'}:00`;
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setSaving(true);
    setFieldErrors({});
    try {
      const payload = {
        ...formData,
        incident_datetime: buildDatetime(incidentDate, incidentTime),
        reportable_incident_type_id: formData.reportable_incident_type_id || null,
        patient_file_number: formData.is_patient_related ? formData.patient_file_number : null,
        patient_name: formData.is_patient_related ? formData.patient_name : null,
      };

      if (isEdit) {
        await incidentsApi.update(reportNumber!, payload);
        showToast('success', t('ovr.incident_updated'));
      } else {
        await incidentsApi.create(payload);
        showToast('success', t('ovr.incident_created'));
      }
      navigate('/ovr/incidents');
    } catch (error: any) {
      const apiErrors = error?.response?.data?.errors as Record<string, string[] | string> | undefined;
      if (apiErrors && typeof apiErrors === 'object') {
        const flattened: Record<string, string> = {};
        Object.entries(apiErrors).forEach(([key, value]) => {
          if (Array.isArray(value)) flattened[key] = String(value[0] ?? '');
          else if (typeof value === 'string') flattened[key] = value;
        });
        setFieldErrors(flattened);
      }
      showToast('error', error?.message || t('common.error_occurred'));
    } finally {
      setSaving(false);
    }
  };

  if (loading) {
    return (
      <div className="flex h-64 items-center justify-center">
        <div className="h-8 w-8 animate-spin rounded-full border-2 border-[var(--border-default)] border-t-[var(--accent-default)]" />
      </div>
    );
  }

  return (
    <div id="main-content" className="space-y-5">
      <SkipToMain label={t('a11y.skip_to_main')} />
      <div className="space-y-4">
        <Breadcrumb
          items={[
            { label: t('ovr.title'), href: '/ovr/incidents' },
            { label: isEdit ? t('ovr.edit_incident') : t('ovr.new_incident') },
          ]}
        />
        <PageHeader
          icon={IconAlertTriangle}
          iconTone="risk"
          title={isEdit ? t('ovr.edit_incident') : t('ovr.new_incident')}
          subtitle={t('ovr.subtitle')}
        />
      </div>

      <form onSubmit={handleSubmit}>
        <div className="grid gap-5 lg:grid-cols-3 lg:items-start">
          {/* العمود الرئيسي */}
          <Card className="space-y-8 p-5 sm:p-7 lg:col-span-2">
            <FormSection title={t('ovr.incident_details')} columns={2}>
              <Select
                label={t('ovr.incident_type')}
                value={formData.incident_type_id}
                onChange={(e) => {
                  setFieldErrors((current) => {
                    if (!current.incident_type_id) return current;
                    const next = { ...current };
                    delete next.incident_type_id;
                    return next;
                  });
                  setFormData({
                    ...formData,
                    incident_type_id: e.target.value,
                    reportable_incident_type_id: '',
                  });
                }}
                required
                error={getFieldError('incident_type_id')}
                placeholder={t('ovr.select_incident_type')}
                options={[
                  { value: '', label: t('ovr.select_incident_type') },
                  ...categories.map((cat) => ({ value: cat.id.toString(), label: cat.name })),
                ]}
              />
              <Select
                label={t('ovr.reportable_type')}
                value={formData.reportable_incident_type_id}
                onChange={(e) =>
                  setFormData({ ...formData, reportable_incident_type_id: e.target.value })
                }
                placeholder={t('ovr.select_reportable_type')}
                disabled={!selectedCategory || !selectedCategory.reportableTypes?.length}
                options={[
                  { value: '', label: t('ovr.select_reportable_type') },
                  ...(selectedCategory?.reportableTypes?.map((rt) => ({
                    value: rt.id.toString(),
                    label: rt.name,
                  })) || []),
                ]}
              />
              <Select
                label={t('ovr.severity')}
                value={formData.severity_level}
                onChange={(e) =>
                  setFormData({ ...formData, severity_level: e.target.value as any })
                }
                required
                error={getFieldError('severity_level')}
                options={[
                  { value: 'low', label: t('ovr.severity_low') },
                  { value: 'medium', label: t('ovr.severity_medium') },
                  { value: 'high', label: t('ovr.severity_high') },
                  { value: 'critical', label: t('ovr.severity_critical') },
                ]}
              />
              <div className="grid grid-cols-2 gap-2">
                <DatePicker
                  label={`${t('ovr.incident_date')} *`}
                  value={incidentDate}
                  onChange={(value) => setIncidentDate(value)}
                  required
                  placeholder={t('ovr.select_incident_date')}
                />
                <Input
                  label={t('ovr.incident_time')}
                  type="time"
                  value={incidentTime}
                  onChange={(e) => setIncidentTime(e.target.value)}
                />
              </div>
            </FormSection>

            <FormSection title={t('ovr.incident_description')}>
              <Textarea
                label={t('ovr.incident_description')}
                value={formData.description}
                onChange={(e) => setFormData({ ...formData, description: e.target.value })}
                rows={4}
                placeholder={t('ovr.description_placeholder')}
                error={getFieldError('description')}
              />
              <Textarea
                label={t('ovr.actions_taken')}
                value={formData.actions_taken}
                onChange={(e) => setFormData({ ...formData, actions_taken: e.target.value })}
                rows={3}
                placeholder={t('ovr.actions_taken_placeholder')}
                error={getFieldError('actions_taken')}
              />
            </FormSection>
          </Card>

          {/* العمود الجانبي */}
          <aside className="space-y-5">
            <Card className="space-y-4 p-5">
              <h2 className="text-sm font-semibold text-[var(--text-primary)]">
                {t('ovr.is_patient_related')}
              </h2>
              <Switch
                label={t('ovr.is_patient_related')}
                checked={formData.is_patient_related}
                onChange={(e) => setFormData({ ...formData, is_patient_related: e.target.checked })}
              />
              {formData.is_patient_related && (
                <div className="space-y-4">
                  <Input
                    label={t('ovr.patient_name')}
                    value={formData.patient_name}
                    onChange={(e) => setFormData({ ...formData, patient_name: e.target.value })}
                    required
                    error={getFieldError('patient_name')}
                  />
                  <Input
                    label={t('ovr.patient_file_number')}
                    value={formData.patient_file_number}
                    onChange={(e) =>
                      setFormData({ ...formData, patient_file_number: e.target.value })
                    }
                    error={getFieldError('patient_file_number')}
                  />
                </div>
              )}
            </Card>

            <Card className="space-y-4 p-5">
              <h2 className="text-sm font-semibold text-[var(--text-primary)]">
                {t('ovr.options')}
              </h2>
              <Switch
                label={t('ovr.immediate_action_required')}
                checked={formData.immediate_action_required}
                onChange={(e) =>
                  setFormData({ ...formData, immediate_action_required: e.target.checked })
                }
              />
              <Switch
                label={t('ovr.is_confidential')}
                checked={formData.is_confidential}
                onChange={(e) => setFormData({ ...formData, is_confidential: e.target.checked })}
              />
            </Card>

            <Card className="p-4">
              <div className="flex flex-col gap-2">
                <Button
                  type="submit"
                  loading={saving}
                  leftIcon={<IconDeviceFloppy className="h-4 w-4" />}
                  className="w-full"
                >
                  {isEdit ? t('common.update') : t('ovr.register')}
                </Button>
                <Button
                  type="button"
                  variant="outline"
                  leftIcon={<IconX className="h-4 w-4" />}
                  onClick={() => navigate('/ovr/incidents')}
                  className="w-full"
                >
                  {t('common.cancel')}
                </Button>
              </div>
            </Card>
          </aside>
        </div>
      </form>
    </div>
  );
};

export default IncidentForm;
