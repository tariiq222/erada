import React, { useState, useEffect, useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import {
  Button,
  Input,
  Select,
  Modal,
  DatePicker,
  Switch,
  Textarea,
  Progress,
  Badge,
  Checkbox,
} from '@shared/ui';
import { useToast } from '@shared/ui/Toast';
import {IconClipboardList, IconFileText, IconStethoscope, IconShieldCheck, IconCircleCheck, IconChevronRight, IconChevronLeft, IconLock} from '@tabler/icons-react';
import { incidentsApi } from '@entities/incident';
import { useAuth } from '@shared/contexts/AuthContext';
import type { Incident, Category, IncidentFormData } from './types';
import {
  severityLabels,
  severityColors,
  severitySlaHints,
  CONTRIBUTING_FACTORS,
  contributingFactorLabels,
} from './constants';

interface IncidentFormWizardProps {
  isOpen: boolean;
  incident: Incident | null;
  categories: Category[];
  onClose: () => void;
  onSuccess: () => void;
}

const emptyForm: IncidentFormData = {
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

// "YYYY-MM-DD" / "HH:mm" for the current local datetime (no-future limits)
const toLocalDateString = (d: Date): string => {
  const year = d.getFullYear();
  const month = String(d.getMonth() + 1).padStart(2, '0');
  const day = String(d.getDate()).padStart(2, '0');
  return `${year}-${month}-${day}`;
};

const toLocalTimeString = (d: Date): string => d.toTimeString().slice(0, 5);

const TOTAL_STEPS = 5;

const IncidentFormWizard: React.FC<IncidentFormWizardProps> = ({
  isOpen,
  incident,
  categories,
  onClose,
  onSuccess,
}) => {
  const { t } = useTranslation();
  const { showToast } = useToast();
  const { user } = useAuth();
  const [isLoading, setIsLoading] = useState(false);
  const [step, setStep] = useState(1);
  const [stepError, setStepError] = useState('');
  const [formData, setFormData] = useState<IncidentFormData>({ ...emptyForm });
  const [incidentDate, setIncidentDate] = useState('');
  const [incidentTime, setIncidentTime] = useState('');
  const [informedAuthority, setInformedAuthority] = useState(false);

  const selectedCategory = useMemo(() => {
    if (!formData.incident_type_id) return null;
    return categories.find((c) => c.id.toString() === formData.incident_type_id) || null;
  }, [formData.incident_type_id, categories]);

  useEffect(() => {
    if (isOpen) {
      setStep(1);
      setStepError('');
      if (incident) {
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
        setInformedAuthority(incident.informed_authority);
        if (incident.incident_datetime) {
          const d = new Date(incident.incident_datetime);
          setIncidentDate(toLocalDateString(d));
          setIncidentTime(toLocalTimeString(d));
        } else {
          setIncidentDate('');
          setIncidentTime('');
        }
      } else {
        setFormData({
          ...emptyForm,
          incident_datetime: new Date().toISOString(),
        });
        setInformedAuthority(false);
        setIncidentDate(toLocalDateString(new Date()));
        setIncidentTime(toLocalTimeString(new Date()));
      }
    }
  }, [isOpen, incident]);

  const buildDatetime = (date: string, time: string): string => {
    if (!date) return '';
    const tm = time || '00:00';
    return `${date}T${tm}:00`;
  };

  // Per-step validation. Returns a ready-to-display error message, or '' when valid.
  const validateStep = (current: number): string => {
    switch (current) {
      case 1:
        if (!formData.incident_type_id) {
          return t('ovr.field_required', { field: t('ovr.select_incident_type') });
        }
        if (
          selectedCategory?.requires_reportable_type &&
          !formData.reportable_incident_type_id
        ) {
          return t('ovr.field_required', { field: t('ovr.sub_type') });
        }
        return '';
      case 2:
        if (!incidentDate) {
          return t('ovr.field_required', { field: t('ovr.select_incident_date') });
        }
        if (new Date(buildDatetime(incidentDate, incidentTime)).getTime() > Date.now()) {
          return t('ovr.no_future_datetime');
        }
        if (!formData.severity_level) {
          return t('ovr.field_required', { field: t('ovr.select_severity') });
        }
        return '';
      case 3:
        if (formData.is_patient_related && !formData.patient_name.trim()) {
          return t('ovr.field_required', { field: t('ovr.patient_name') });
        }
        return '';
      default:
        return '';
    }
  };

  const toggleContributingFactor = (factor: string, checked: boolean) => {
    setFormData((prev) => ({
      ...prev,
      contributing_factors: checked
        ? [...prev.contributing_factors, factor]
        : prev.contributing_factors.filter((f) => f !== factor),
    }));
  };

  const handleNext = () => {
    const error = validateStep(step);
    if (error) {
      setStepError(error);
      return;
    }
    setStepError('');
    setStep((s) => Math.min(s + 1, TOTAL_STEPS));
  };

  const handleBack = () => {
    setStepError('');
    setStep((s) => Math.max(s - 1, 1));
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();

    // Guard: re-validate every gating step before final submit.
    for (let s = 1; s <= TOTAL_STEPS; s += 1) {
      const error = validateStep(s);
      if (error) {
        setStep(s);
        setStepError(error);
        return;
      }
    }

    setIsLoading(true);

    try {
      const payload = {
        ...formData,
        incident_datetime: buildDatetime(incidentDate, incidentTime),
        reportable_incident_type_id: formData.reportable_incident_type_id || null,
        patient_file_number: formData.is_patient_related ? formData.patient_file_number : null,
        patient_name: formData.is_patient_related ? formData.patient_name : null,
        informed_authority: informedAuthority,
      };

      if (incident) {
        await incidentsApi.update(incident.report_number, payload);
        showToast('success', t('ovr.incident_updated'));
      } else {
        await incidentsApi.create(payload);
        showToast('success', t('ovr.incident_created'));
      }
      onSuccess();
    } catch (error: any) {
      showToast('error', error.message || t('common.error_occurred'));
    } finally {
      setIsLoading(false);
    }
  };

  const steps = [
    { id: 1, label: t('ovr.step_incident_type'), icon: IconClipboardList },
    { id: 2, label: t('ovr.step_details'), icon: IconFileText },
    { id: 3, label: t('ovr.step_patient'), icon: IconStethoscope },
    { id: 4, label: t('ovr.step_actions'), icon: IconShieldCheck },
    { id: 5, label: t('ovr.review_submit'), icon: IconCircleCheck },
  ];

  const summaryRow = (label: string, value: React.ReactNode) => (
    <div className="flex items-start justify-between gap-4 py-2 border-b border-[var(--border-default)] last:border-0">
      <span className="text-xs text-[var(--text-tertiary)] shrink-0">{label}</span>
      <span className="text-sm font-medium text-[var(--text-primary)] text-left">{value || '-'}</span>
    </div>
  );

  return (
    <Modal
      isOpen={isOpen}
      onClose={onClose}
      title={incident ? t('ovr.edit_incident') : t('ovr.new_incident')}
      size="lg"
    >
      <form onSubmit={handleSubmit} className="space-y-5">
        {/* Stepper */}
        <div className="space-y-3">
          <div className="flex items-center justify-between">
            {steps.map((s, idx) => {
              const StepIcon = s.icon;
              const isActive = s.id === step;
              const isDone = s.id < step;
              return (
                <React.Fragment key={s.id}>
                  <div className="flex flex-col items-center gap-1 flex-1 min-w-0">
                    <div
                      className={
                        'h-8 w-8 rounded-full flex items-center justify-center transition-colors shrink-0 ' +
                        (isActive || isDone
                          ? 'bg-[var(--accent-default)] text-[var(--text-inverse)]'
                          : 'bg-[var(--surface-muted)] text-[var(--text-tertiary)]')
                      }
                    >
                      {isDone ? (
                        <IconCircleCheck className="h-4 w-4" />
                      ) : (
                        <StepIcon className="h-4 w-4" />
                      )}
                    </div>
                    <span
                      className={
                        'text-[10px] text-center truncate w-full hidden sm:block ' +
                        (isActive
                          ? 'text-[var(--text-primary)]'
                          : 'text-[var(--text-tertiary)]')
                      }
                    >
                      {s.label}
                    </span>
                  </div>
                  {idx < steps.length - 1 && (
                    <div
                      className={
                        'h-px flex-1 mb-4 sm:mb-5 ' +
                        (s.id < step
                          ? 'bg-[var(--accent-default)]'
                          : 'bg-[var(--border-default)]')
                      }
                    />
                  )}
                </React.Fragment>
              );
            })}
          </div>
          <Progress value={step} max={TOTAL_STEPS} size="sm" />
          <p className="text-xs text-[var(--text-tertiary)] text-center">
            {t('ovr.step_x_of_y', { current: step, total: TOTAL_STEPS })}
          </p>
        </div>

        {/* Step 1: نوع الحادثة */}
        {step === 1 && (
          <div className="space-y-4">
            {/* بطاقة المبلّغ - للقراءة فقط، تُعبأ من حساب المستخدم */}
            <div
              data-testid="reporter-card"
              className="p-4 rounded-lg bg-[var(--surface-muted)] border border-[var(--border-default)] space-y-3"
            >
              <div className="flex items-center gap-2">
                <IconLock className="h-4 w-4 text-[var(--text-tertiary)]" aria-hidden="true" />
                <span className="text-sm font-medium text-[var(--text-primary)]">
                  {t('ovr.reporter')}
                </span>
              </div>
              <div className="grid grid-cols-2 gap-x-4 gap-y-2">
                {user?.name && (
                  <div>
                    <p className="text-xs text-[var(--text-tertiary)]">{t('common.name')}</p>
                    <p className="text-sm font-medium text-[var(--text-primary)]">{user.name}</p>
                  </div>
                )}
                {user?.job_title && (
                  <div>
                    <p className="text-xs text-[var(--text-tertiary)]">{t('users.job_title')}</p>
                    <p className="text-sm font-medium text-[var(--text-primary)]">
                      {user.job_title}
                    </p>
                  </div>
                )}
                {user?.department?.name && (
                  <div>
                    <p className="text-xs text-[var(--text-tertiary)]">{t('common.department')}</p>
                    <p className="text-sm font-medium text-[var(--text-primary)]">
                      {user.department.name}
                    </p>
                  </div>
                )}
                {user?.extension && (
                  <div>
                    <p className="text-xs text-[var(--text-tertiary)]">{t('users.extension')}</p>
                    <p className="text-sm font-medium text-[var(--text-primary)]">
                      {user.extension}
                    </p>
                  </div>
                )}
              </div>
              <p className="text-xs text-[var(--text-tertiary)]">
                {t('ovr.reporter_autofill_hint')}
              </p>
            </div>

            <div className="grid grid-cols-2 gap-4">
              <Select
                label={t('ovr.incident_type')}
                value={formData.incident_type_id}
                onChange={(e) =>
                  setFormData({
                    ...formData,
                    incident_type_id: e.target.value,
                    reportable_incident_type_id: '',
                  })
                }
                required
                placeholder={t('ovr.select_incident_type')}
                options={[
                  { value: '', label: t('ovr.select_incident_type') },
                  ...categories.map((cat) => ({
                    value: cat.id.toString(),
                    label: cat.name,
                  })),
                ]}
              />
              <Select
                label={t('ovr.sub_type')}
                value={formData.reportable_incident_type_id}
                onChange={(e) =>
                  setFormData({ ...formData, reportable_incident_type_id: e.target.value })
                }
                required={Boolean(selectedCategory?.requires_reportable_type)}
                placeholder={
                  selectedCategory?.requires_reportable_type
                    ? t('ovr.select_reportable_type')
                    : t('common.optional')
                }
                disabled={!selectedCategory || !selectedCategory.reportableTypes?.length}
                options={[
                  {
                    value: '',
                    label: selectedCategory?.requires_reportable_type
                      ? t('ovr.select_reportable_type')
                      : t('common.optional'),
                  },
                  ...(selectedCategory?.reportableTypes?.map((rt) => ({
                    value: rt.id.toString(),
                    label: rt.name,
                  })) || []),
                ]}
              />
            </div>
          </div>
        )}

        {/* Step 2: تفاصيل الحادثة */}
        {step === 2 && (
          <div className="space-y-4">
            <div className="grid grid-cols-2 gap-4">
              <Select
                label={t('ovr.severity')}
                value={formData.severity_level}
                onChange={(e) =>
                  setFormData({ ...formData, severity_level: e.target.value as any })
                }
                required
                hint={t(severitySlaHints[formData.severity_level])}
                options={(['low', 'medium', 'high', 'critical'] as const).map((lvl) => ({
                  value: lvl,
                  label: `${t(severityLabels[lvl])} - ${t(severitySlaHints[lvl])}`,
                }))}
              />
              <div className="grid grid-cols-2 gap-2">
                <DatePicker
                  label={`${t('ovr.incident_date')} *`}
                  value={incidentDate}
                  onChange={(value) => setIncidentDate(value)}
                  required
                  maxDate={toLocalDateString(new Date())}
                  hint={t('ovr.no_future_datetime')}
                  placeholder={t('ovr.select_incident_date')}
                />
                <Input
                  label={t('ovr.incident_time')}
                  type="time"
                  value={incidentTime}
                  max={
                    incidentDate === toLocalDateString(new Date())
                      ? toLocalTimeString(new Date())
                      : undefined
                  }
                  onChange={(e) => setIncidentTime(e.target.value)}
                />
              </div>
            </div>
            <Textarea
              label={t('ovr.incident_description')}
              value={formData.description}
              onChange={(e) => setFormData({ ...formData, description: e.target.value })}
              rows={4}
              placeholder={t('ovr.description_placeholder')}
            />
          </div>
        )}

        {/* Step 3: بيانات المريض */}
        {step === 3 && (
          <div className="p-3 border border-[var(--border-default)] rounded-lg space-y-3">
            <Switch
              label={t('ovr.is_patient_related')}
              checked={formData.is_patient_related}
              onChange={(e) =>
                setFormData({ ...formData, is_patient_related: e.target.checked })
              }
            />
            {formData.is_patient_related ? (
              <div className="grid grid-cols-2 gap-4">
                <Input
                  label={t('ovr.patient_name')}
                  value={formData.patient_name}
                  onChange={(e) => setFormData({ ...formData, patient_name: e.target.value })}
                />
                <Input
                  label={t('ovr.patient_file_number')}
                  value={formData.patient_file_number}
                  onChange={(e) =>
                    setFormData({ ...formData, patient_file_number: e.target.value })
                  }
                />
              </div>
            ) : (
              <p className="text-xs text-[var(--text-tertiary)]">
                {t('ovr.patient_not_related_hint')}
              </p>
            )}
          </div>
        )}

        {/* Step 4: الإجراءات */}
        {step === 4 && (
          <div className="space-y-4">
            <Textarea
              label={t('ovr.actions_taken')}
              value={formData.actions_taken}
              onChange={(e) => setFormData({ ...formData, actions_taken: e.target.value })}
              rows={3}
              placeholder={t('ovr.actions_taken_placeholder')}
            />
            <div className="space-y-2">
              <p className="text-sm font-medium text-[var(--text-secondary)]">
                {t('ovr.contributing_factors')}
              </p>
              <div className="grid grid-cols-2 gap-2">
                {CONTRIBUTING_FACTORS.map((factor) => (
                  <Checkbox
                    key={factor}
                    label={t(contributingFactorLabels[factor])}
                    checked={formData.contributing_factors.includes(factor)}
                    onChange={(e) => toggleContributingFactor(factor, e.target.checked)}
                  />
                ))}
              </div>
            </div>
            <div className="grid grid-cols-2 gap-4">
              <Switch
                label={t('ovr.immediate_action_required')}
                checked={formData.immediate_action_required}
                onChange={(e) =>
                  setFormData({ ...formData, immediate_action_required: e.target.checked })
                }
              />
              <Switch
                label={t('ovr.informed_authority')}
                checked={informedAuthority}
                onChange={(e) => setInformedAuthority(e.target.checked)}
              />
            </div>
          </div>
        )}

        {/* Step 5: مراجعة وإرسال */}
        {step === 5 && (
          <div className="space-y-4">
            <div className="p-4 rounded-lg bg-[var(--surface-muted)] space-y-1">
              {summaryRow(
                t('ovr.incident_type'),
                selectedCategory?.name || '-'
              )}
              {summaryRow(
                t('ovr.severity'),
                <Badge variant={severityColors[formData.severity_level]} size="sm">
                  {t(severityLabels[formData.severity_level])}
                </Badge>
              )}
              {summaryRow(
                t('ovr.incident_date'),
                `${incidentDate || '-'}${incidentTime ? ` ${incidentTime}` : ''}`
              )}
              {summaryRow(
                t('ovr.incident_description'),
                formData.description || '-'
              )}
              {summaryRow(
                t('ovr.is_patient_related'),
                formData.is_patient_related
                  ? `${formData.patient_name || '-'}${
                      formData.patient_file_number ? ` (${formData.patient_file_number})` : ''
                    }`
                  : t('common.no')
              )}
              {summaryRow(
                t('ovr.actions_taken'),
                formData.actions_taken || '-'
              )}
              {summaryRow(
                t('ovr.contributing_factors'),
                formData.contributing_factors.length
                  ? formData.contributing_factors
                      .map((factor) => t(contributingFactorLabels[factor]))
                      .join('، ')
                  : '-'
              )}
              {summaryRow(
                t('ovr.immediate_action_required'),
                formData.immediate_action_required ? t('common.yes') : t('common.no')
              )}
              {summaryRow(
                t('ovr.informed_authority'),
                informedAuthority ? t('common.yes') : t('common.no')
              )}
            </div>
            <div className="p-3 border border-[var(--border-default)] rounded-lg">
              <Switch
                label={t('ovr.is_confidential')}
                checked={formData.is_confidential}
                onChange={(e) =>
                  setFormData({ ...formData, is_confidential: e.target.checked })
                }
              />
            </div>
          </div>
        )}

        {/* Inline step error */}
        {stepError && (
          <p className="text-sm" style={{ color: 'var(--status-danger)' }}>
            {stepError}
          </p>
        )}

        {/* Footer */}
        <div className="flex gap-3 pt-4 border-t border-[var(--border-default)]">
          {step === 1 ? (
            <Button type="button" variant="outline" onClick={onClose} className="flex-1">
              {t('common.cancel')}
            </Button>
          ) : (
            <Button
              type="button"
              variant="outline"
              onClick={handleBack}
              className="flex-1 flex items-center justify-center gap-1"
            >
              <IconChevronRight className="h-4 w-4 rtl:block ltr:hidden" />
              <IconChevronLeft className="h-4 w-4 rtl:hidden ltr:block" />
              {t('common.back')}
            </Button>
          )}
          {step < TOTAL_STEPS ? (
            <Button
              type="button"
              onClick={handleNext}
              className="flex-1 flex items-center justify-center gap-1"
            >
              {t('common.next')}
              <IconChevronLeft className="h-4 w-4 rtl:block ltr:hidden" />
              <IconChevronRight className="h-4 w-4 rtl:hidden ltr:block" />
            </Button>
          ) : (
            <Button type="submit" loading={isLoading} className="flex-1">
              {incident ? t('common.update') : t('ovr.register')}
            </Button>
          )}
        </div>
      </form>
    </Modal>
  );
};

export default IncidentFormWizard;
