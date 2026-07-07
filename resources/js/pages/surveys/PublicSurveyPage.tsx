import React, { useEffect, useState, useMemo } from 'react';
import { useParams, useSearchParams } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { publicSurveysApi } from '@entities/survey';
import { Button, Card, DatePicker, Input, RadioGroup, Radio, Skeleton, Select, Checkbox } from '@shared/ui';
import { RequiredIndicator } from '@shared/ui/RequiredIndicator';
import { useToast } from '@shared/ui/Toast';
import {IconClipboardList, IconSend, IconCircleCheck, IconAlertCircle, IconClock, IconLock, IconChevronLeft, IconChevronRight, IconCheck} from '@tabler/icons-react';

interface SurveyField {
  id: number;
  field_key: string;
  label: string;
  description: string | null;
  type: string;
  config: Record<string, any>;
  is_required: boolean;
  order: number;
  is_visible?: boolean;
}

interface SurveySection {
  id: number;
  title: string;
  description: string | null;
  order: number;
  is_visible?: boolean;
  fields: SurveyField[];
}

interface PublicSurvey {
  code: string;
  title: string;
  description: string | null;
  welcome_message: string | null;
  thank_you_message: string | null;
  consent_required: boolean;
  consent_text: string | null;
  fields: SurveyField[];
  sections?: SurveySection[];
}

type PageState = 'loading' | 'welcome' | 'form' | 'submitting' | 'success' | 'error';

interface Step {
  id: string;
  title: string;
  description?: string | null;
  fields: SurveyField[];
}

const GROUP_FIELD_TYPES = new Set(['radio', 'checkbox', 'rating', 'scale']);

const toDomIdSegment = (value: string) => value.replace(/[^A-Za-z0-9_-]/g, '-') || 'item';

const PublicSurveyPage: React.FC = () => {
  const { t } = useTranslation();
  const { code } = useParams<{ code: string }>();
  const [searchParams] = useSearchParams();
  const revision = searchParams.get('rev');

  const [survey, setSurvey] = useState<PublicSurvey | null>(null);
  const [versionHash, setVersionHash] = useState<string>('');
  const [pageState, setPageState] = useState<PageState>('loading');
  const [errorMessage, setErrorMessage] = useState<string>('');
  const [answers, setAnswers] = useState<Record<string, any>>({});
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const [consentAccepted, setConsentAccepted] = useState(false);
  const [currentStep, setCurrentStep] = useState(0);
  const { showToast } = useToast();

  // بناء الخطوات من الأقسام
  const steps = useMemo<Step[]>(() => {
    if (!survey) return [];

    const stepsArray: Step[] = [];

    // إذا كان هناك أقسام، استخدمها كخطوات
    if (survey.sections && survey.sections.length > 0) {
      const visibleSections = survey.sections
        .filter((s) => s.is_visible !== false)
        .sort((a, b) => a.order - b.order);

      visibleSections.forEach((section) => {
        const visibleFields = section.fields
          .filter((f) => f.is_visible !== false)
          .sort((a, b) => a.order - b.order);

        if (visibleFields.length > 0) {
          stepsArray.push({
            id: `section-${section.id}`,
            title: section.title,
            description: section.description,
            fields: visibleFields,
          });
        }
      });

      // إضافة الحقول بدون قسم كخطوة أخيرة (إن وجدت)
      const fieldsWithoutSection = survey.fields.filter((f) => f.is_visible !== false);
      // تجنب تكرار الحقول الموجودة في الأقسام
      const sectionFieldIds = new Set(
        survey.sections.flatMap((s) => s.fields.map((f) => f.id))
      );
      const orphanFields = fieldsWithoutSection.filter((f) => !sectionFieldIds.has(f.id));

      if (orphanFields.length > 0 && stepsArray.length > 0) {
        stepsArray.push({
          id: 'other-fields',
          title: t('surveys.additional_info'),
          description: null,
          fields: orphanFields.sort((a, b) => a.order - b.order),
        });
      } else if (orphanFields.length > 0 && stepsArray.length === 0) {
        // إذا لم يكن هناك أقسام، اعرض كل الحقول في خطوة واحدة
        stepsArray.push({
          id: 'all-fields',
          title: survey.title,
          description: survey.description,
          fields: orphanFields.sort((a, b) => a.order - b.order),
        });
      }
    } else {
      // إذا لم يكن هناك أقسام، اعرض كل الحقول في خطوة واحدة
      const visibleFields = survey.fields
        .filter((f) => f.is_visible !== false)
        .sort((a, b) => a.order - b.order);

      if (visibleFields.length > 0) {
        stepsArray.push({
          id: 'all-fields',
          title: survey.title,
          description: survey.description,
          fields: visibleFields,
        });
      }
    }

    return stepsArray;
  }, [survey]);

  const totalSteps = steps.length;
  const isMultiStep = totalSteps > 1;
  const isLastStep = currentStep === totalSteps - 1;
  const isFirstStep = currentStep === 0;

  // جلب بيانات الاستبيان
  const fetchSurvey = async () => {
    if (!code) return;

    try {
      setPageState('loading');
      const response = await publicSurveysApi.getByCode(
        code,
        revision ? parseInt(revision) : undefined
      );
      const data = response as any;

      setSurvey(data.data);
      setVersionHash(data.version_hash ?? '');

      if (data.data.welcome_message) {
        setPageState('welcome');
      } else {
        setPageState('form');
      }
    } catch (error: any) {
      console.error('Failed to fetch survey:', error);
      const message = error.message || t('surveys.not_available');
      setErrorMessage(message);
      setPageState('error');
    }
  };

  useEffect(() => {
    fetchSurvey();
  }, [code, revision]);

  // تحديث الإجابة
  const handleAnswerChange = (fieldKey: string, value: any) => {
    setAnswers((prev) => ({
      ...prev,
      [fieldKey]: value,
    }));
    setFieldErrors((prev) => {
      if (!prev[fieldKey]) return prev;

      const next = { ...prev };
      delete next[fieldKey];
      return next;
    });
  };

  const isRequiredAnswerMissing = (answer: unknown): boolean => {
    return (
      answer === undefined ||
      answer === null ||
      answer === '' ||
      (Array.isArray(answer) && answer.length === 0)
    );
  };

  const collectRequiredFieldErrors = (fields: SurveyField[]) => {
    const errors: Record<string, string> = {};
    let firstError: string | undefined;

    for (const field of fields) {
      if (!field.is_required) continue;

      const answer = answers[field.field_key];
      if (!isRequiredAnswerMissing(answer)) continue;

      const message = t('surveys.field_required', { field: field.label });
      errors[field.field_key] = message;
      firstError ??= message;
    }

    return { errors, firstError };
  };

  const updateFieldErrorsForFields = (fields: SurveyField[], errors: Record<string, string>) => {
    setFieldErrors((prev) => {
      const next = { ...prev };

      fields.forEach((field) => {
        delete next[field.field_key];
      });

      return { ...next, ...errors };
    });
  };

  // التحقق من صحة الخطوة الحالية
  const validateCurrentStep = (): boolean => {
    if (!steps[currentStep]) return true;

    const currentFields = steps[currentStep].fields;
    const { errors, firstError } = collectRequiredFieldErrors(currentFields);
    updateFieldErrorsForFields(currentFields, errors);

    if (firstError) {
      showToast('error', firstError);
      return false;
    }

    return true;
  };

  // التحقق من صحة النموذج بالكامل
  const validateForm = (): boolean => {
    if (!survey) return false;

    // التحقق من الموافقة
    if (survey.consent_required && !consentAccepted) {
      showToast('error', t('surveys.consent_required_error'));
      return false;
    }

    // التحقق من جميع الحقول المطلوبة
    const allFields = steps.flatMap((s) => s.fields);
    const { errors, firstError } = collectRequiredFieldErrors(allFields);
    updateFieldErrorsForFields(allFields, errors);

    if (firstError) {
      showToast('error', firstError);
      return false;
    }

    return true;
  };

  const getFieldIds = (field: SurveyField) => {
    const baseId = `public-survey-field-${field.id}`;

    return {
      controlId: `${baseId}-control`,
      labelId: `${baseId}-label`,
      descriptionId: `${baseId}-description`,
      errorId: `${baseId}-error`,
    };
  };

  const getFieldDescribedBy = (field: SurveyField) => {
    const ids = getFieldIds(field);
    const describedBy = [];

    if (field.description) describedBy.push(ids.descriptionId);
    if (fieldErrors[field.field_key]) describedBy.push(ids.errorId);

    return describedBy.length > 0 ? describedBy.join(' ') : undefined;
  };

  const scrollToTop = () => {
    if (typeof window === 'undefined') return;

    const prefersReducedMotion =
      typeof window.matchMedia === 'function' &&
      window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    window.scrollTo({ top: 0, behavior: prefersReducedMotion ? 'auto' : 'smooth' });
  };

  // الانتقال للخطوة التالية
  const handleNext = () => {
    if (!validateCurrentStep()) return;

    if (isLastStep) {
      handleSubmit();
    } else {
      setCurrentStep((prev) => prev + 1);
      scrollToTop();
    }
  };

  // الرجوع للخطوة السابقة
  const handlePrevious = () => {
    if (!isFirstStep) {
      setCurrentStep((prev) => prev - 1);
      scrollToTop();
    }
  };

  // إرسال الإجابات
  const handleSubmit = async () => {
    if (!validateForm() || !code) return;

    setPageState('submitting');

    try {
      await publicSurveysApi.submit(code, {
        answers,
        version_hash: versionHash,
        fingerprint: generateFingerprint(),
      });

      setPageState('success');
    } catch (error: any) {
      console.error('Failed to submit:', error);
      const message = error.response?.data?.message || t('surveys.submit_failed');
      showToast('error', message);
      setPageState('form');
    }
  };

  // توليد بصمة المتصفح
  const generateFingerprint = (): string => {
    const nav = navigator;
    return window.btoa(
      `${nav.userAgent}|${nav.language}|${window.screen.width}x${window.screen.height}|${new Date().getTimezoneOffset()}`
    );
  };

  // عرض حقل النموذج
  const renderField = (field: SurveyField) => {
    const value = answers[field.field_key] ?? '';
    const ids = getFieldIds(field);
    const fieldError = fieldErrors[field.field_key];
    const describedBy = getFieldDescribedBy(field);
    const commonAriaProps = {
      'aria-labelledby': ids.labelId,
      'aria-describedby': describedBy,
      'aria-invalid': Boolean(fieldError),
      'aria-required': field.is_required || undefined,
    };

    switch (field.type) {
      case 'text':
      case 'email':
      case 'phone':
        return (
          <Input
            id={ids.controlId}
            type={field.type === 'phone' ? 'tel' : field.type}
            value={value}
            onChange={(e) => handleAnswerChange(field.field_key, e.target.value)}
            placeholder={field.description || t('surveys.enter_field', { field: field.label })}
            required={field.is_required}
            dir={field.type === 'email' ? 'ltr' : 'rtl'}
            {...commonAriaProps}
          />
        );

      case 'textarea':
        return (
          <textarea
            id={ids.controlId}
            value={value}
            onChange={(e) => handleAnswerChange(field.field_key, e.target.value)}
            placeholder={field.description || t('surveys.enter_field', { field: field.label })}
            required={field.is_required}
            rows={4}
            {...commonAriaProps}
            className="w-full px-3 py-2 border border-[var(--border-default)] rounded-lg bg-[var(--surface-muted)] text-[var(--text-primary)] text-sm focus:outline-none focus:ring-2 focus:ring-[var(--accent-default)] focus:border-transparent resize-none"
          />
        );

      case 'number':
        return (
          <Input
            id={ids.controlId}
            type="number"
            value={value}
            onChange={(e) => handleAnswerChange(field.field_key, e.target.value)}
            placeholder={field.description || t('surveys.enter_field', { field: field.label })}
            required={field.is_required}
            min={field.config?.min}
            max={field.config?.max}
            {...commonAriaProps}
          />
        );

      case 'date':
        return (
          <DatePicker
            id={ids.controlId}
            value={value}
            onChange={(nextValue) => handleAnswerChange(field.field_key, nextValue)}
            required={field.is_required}
            {...commonAriaProps}
          />
        );

      case 'time':
        return (
          <Input
            id={ids.controlId}
            type="time"
            value={value}
            onChange={(e) => handleAnswerChange(field.field_key, e.target.value)}
            required={field.is_required}
            {...commonAriaProps}
          />
        );

      case 'datetime':
        return (
          <Input
            id={ids.controlId}
            type="datetime-local"
            value={value}
            onChange={(e) => handleAnswerChange(field.field_key, e.target.value)}
            required={field.is_required}
            {...commonAriaProps}
          />
        );

      case 'select': {
        const selectOptions = field.config?.options || [];
        return (
          <Select
            id={ids.controlId}
            value={value}
            onChange={(e) => handleAnswerChange(field.field_key, e.target.value)}
            required={field.is_required}
            placeholder={t('common.select')}
            options={selectOptions.map((opt: any) => ({
              value: String(opt.value),
              label: String(opt.label),
            }))}
            {...commonAriaProps}
          />
        );
      }

      case 'radio': {
        const radioOptions = field.config?.options || [];
        return (
          <RadioGroup
            id={ids.controlId}
            name={field.field_key}
            value={value}
            onChange={(v) => handleAnswerChange(field.field_key, v)}
            className="space-y-2"
            {...commonAriaProps}
          >
            {radioOptions.map((opt: any) => {
              const optionId = `${ids.controlId}-${toDomIdSegment(String(opt.value))}`;

              return (
                <Radio
                  key={opt.value}
                  id={optionId}
                  value={opt.value}
                  label={String(opt.label)}
                />
              );
            })}
          </RadioGroup>
        );
      }

      case 'checkbox': {
        const checkboxOptions = field.config?.options || [];
        const selectedValues = Array.isArray(value) ? value : [];
        return (
          <div
            id={ids.controlId}
            role="group"
            className="space-y-2"
            {...commonAriaProps}
          >
            {checkboxOptions.map((opt: any) => {
              const optionId = `${ids.controlId}-${toDomIdSegment(String(opt.value))}`;
              const isSelected = selectedValues.includes(opt.value);

              return (
                <Checkbox
                  key={String(opt.value)}
                  id={optionId}
                  value={opt.value}
                  checked={isSelected}
                  onChange={(e) => {
                    const newValues = e.target.checked
                      ? [...selectedValues, opt.value]
                      : selectedValues.filter((v: string) => v !== opt.value);
                    handleAnswerChange(field.field_key, newValues);
                  }}
                  label={String(opt.label)}
                />
              );
            })}
          </div>
        );
      }

      case 'rating': {
        const maxRating = field.config?.max || 5;
        return (
          <div id={ids.controlId} role="group" className="flex items-center gap-2" {...commonAriaProps}>
            {Array.from({ length: maxRating }, (_, i) => i + 1).map((rating) => (
              <button
                key={rating}
                type="button"
                onClick={() => handleAnswerChange(field.field_key, rating)}
                aria-label={t('surveys.rating_value', { value: rating })}
                aria-pressed={value >= rating}
                className={`h-11 w-11 rounded-full border-2 transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--accent-default)] focus-visible:ring-offset-2 focus-visible:ring-offset-[var(--surface-base)] ${
                  value >= rating
                    ? 'bg-[var(--accent-default)] border-[var(--accent-default)] text-[var(--text-inverse)]'
                    : 'border-[var(--border-strong)] text-[var(--text-tertiary)] hover:border-[var(--accent-default)]'
                }`}
              >
                ★
              </button>
            ))}
          </div>
        );
      }

      case 'scale': {
        const minScale = field.config?.min || 1;
        const maxScale = field.config?.max || 10;
        return (
          <div id={ids.controlId} role="group" className="space-y-2" {...commonAriaProps}>
            <div className="flex flex-wrap items-center gap-2">
              {Array.from({ length: maxScale - minScale + 1 }, (_, i) => minScale + i).map(
                (num) => (
                  <button
                    key={num}
                    type="button"
                    onClick={() => handleAnswerChange(field.field_key, num)}
                    aria-label={String(num)}
                    aria-pressed={value === num}
                    className={`h-11 w-11 shrink-0 rounded-lg text-sm font-medium transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--accent-default)] focus-visible:ring-offset-2 focus-visible:ring-offset-[var(--surface-base)] ${
                      value === num
                        ? 'bg-[var(--accent-default)] text-[var(--text-inverse)]'
                        : 'bg-[var(--surface-muted)] text-[var(--text-secondary)] hover:bg-[var(--accent-subtle)] hover:text-[var(--accent-default)]'
                    }`}
                  >
                    {num}
                  </button>
                )
              )}
            </div>
            <div className="flex justify-between text-xs text-[var(--text-tertiary)]">
              <span>{field.config?.minLabel || t('surveys.scale_min')}</span>
              <span>{field.config?.maxLabel || t('surveys.scale_max')}</span>
            </div>
          </div>
        );
      }

      default:
        return (
          <Input
            id={ids.controlId}
            type="text"
            value={value}
            onChange={(e) => handleAnswerChange(field.field_key, e.target.value)}
            placeholder={field.description || t('surveys.enter_field', { field: field.label })}
            required={field.is_required}
            {...commonAriaProps}
          />
        );
    }
  };

  // صفحة التحميل
  if (pageState === 'loading') {
    return (
      <div className="min-h-screen bg-[var(--surface-subtle)] py-8 px-4">
        <div className="max-w-2xl mx-auto">
          <div className="text-center mb-8">
            <Skeleton className="h-12 w-12 rounded-xl mx-auto mb-4" />
            <Skeleton className="h-8 w-64 mx-auto mb-2" />
            <Skeleton className="h-4 w-48 mx-auto" />
          </div>
          <Card className="p-6 border border-[var(--border-default)]">
            <div className="space-y-6">
              {[1, 2, 3].map((i) => (
                <div key={i} className="space-y-2">
                  <Skeleton className="h-4 w-32" />
                  <Skeleton className="h-10 w-full rounded-lg" />
                </div>
              ))}
            </div>
          </Card>
        </div>
      </div>
    );
  }

  // صفحة الخطأ
  if (pageState === 'error') {
    return (
      <div className="min-h-screen bg-[var(--surface-subtle)] flex items-center justify-center py-8 px-4">
        <Card className="max-w-md w-full p-8 text-center border border-[var(--border-default)]">
          <div className="w-16 h-16 rounded-full bg-[var(--status-danger-subtle)] flex items-center justify-center mx-auto mb-4">
            <IconAlertCircle className="w-8 h-8 text-[var(--status-danger)]" />
          </div>
          <h1 className="text-xl font-bold text-[var(--text-primary)] mb-2">
            {t('surveys.not_available')}
          </h1>
          <p className="text-[var(--text-secondary)]">{errorMessage}</p>
        </Card>
      </div>
    );
  }

  // صفحة النجاح
  if (pageState === 'success') {
    return (
      <div className="min-h-screen bg-[var(--surface-subtle)] flex items-center justify-center py-8 px-4">
        <Card className="max-w-md w-full p-8 text-center border border-[var(--border-default)]">
          <div className="w-16 h-16 rounded-full bg-[var(--status-success-subtle)] flex items-center justify-center mx-auto mb-4">
            <IconCircleCheck className="w-8 h-8 text-[var(--status-success)]" />
          </div>
          <h1 className="text-xl font-bold text-[var(--text-primary)] mb-2">
            {t('surveys.submission_success')}
          </h1>
          <p className="text-[var(--text-secondary)]">
            {survey?.thank_you_message || t('surveys.default_thank_you')}
          </p>
        </Card>
      </div>
    );
  }

  if (!survey) return null;

  // صفحة الترحيب
  if (pageState === 'welcome') {
    const totalFields = steps.reduce((acc, s) => acc + s.fields.length, 0);

    return (
      <div className="min-h-screen bg-[var(--surface-subtle)] flex items-center justify-center py-8 px-4">
        <Card className="max-w-lg w-full p-8 border border-[var(--border-default)]">
          <div className="text-center mb-6">
            <div className="w-14 h-14 rounded-xl bg-[var(--accent-subtle)] flex items-center justify-center mx-auto mb-4">
              <IconClipboardList className="w-7 h-7 text-[var(--accent-default)]" />
            </div>
            <h1 className="text-2xl font-bold text-[var(--text-primary)] mb-2">
              {survey.title}
            </h1>
            {survey.description && (
              <p className="text-[var(--text-secondary)]">{survey.description}</p>
            )}
          </div>

          {survey.welcome_message && (
            <div className="bg-[var(--accent-subtle)] rounded-lg p-4 mb-6">
              <p className="text-[var(--text-primary)] whitespace-pre-wrap">
                {survey.welcome_message}
              </p>
            </div>
          )}

          <div className="flex items-center justify-center gap-4 text-sm text-[var(--text-secondary)] mb-6">
            <div className="flex items-center gap-2">
              <IconClock className="w-4 h-4" />
              <span>{totalFields} {t('surveys.question')}</span>
            </div>
            {isMultiStep && (
              <div className="flex items-center gap-2">
                <span>•</span>
                <span>{totalSteps} {t('surveys.sections')}</span>
              </div>
            )}
          </div>

          <Button onClick={() => setPageState('form')} className="w-full">
            {t('surveys.start_survey')}
          </Button>
        </Card>
      </div>
    );
  }

  const currentStepData = steps[currentStep];

  // صفحة النموذج
  return (
    <div className="min-h-screen bg-[var(--surface-subtle)] py-8 px-4">
      <div className="max-w-2xl mx-auto">
        {/* Header */}
        <div className="text-center mb-6">
          <div className="w-12 h-12 rounded-xl bg-[var(--accent-subtle)] flex items-center justify-center mx-auto mb-4">
            <IconClipboardList className="w-6 h-6 text-[var(--accent-default)]" />
          </div>
          <h1 className="text-2xl font-bold text-[var(--text-primary)] mb-2">{survey.title}</h1>
        </div>

        {/* شريط التقدم (فقط إذا كان هناك أكثر من خطوة) */}
        {isMultiStep && (
          <div className="mb-8">
            {/* Steps indicator */}
            <div className="flex items-center justify-center mb-4">
              {steps.map((step, index) => (
                <React.Fragment key={step.id}>
                  {/* Step circle */}
                  <div
                    className={`flex items-center justify-center w-8 h-8 rounded-full text-sm font-medium transition-colors ${
                      index < currentStep
                        ? 'bg-[var(--status-success)] text-[var(--text-inverse)]'
                        : index === currentStep
                          ? 'bg-[var(--accent-default)] text-[var(--text-inverse)]'
                          : 'bg-[var(--surface-muted)] text-[var(--text-tertiary)]'
                    }`}
                  >
                    {index < currentStep ? <IconCheck className="w-4 h-4" /> : index + 1}
                  </div>
                  {/* Connector line */}
                  {index < steps.length - 1 && (
                    <div
                      className={`w-12 h-1 mx-1 rounded ${
                        index < currentStep ? 'bg-[var(--status-success)]' : 'bg-[var(--surface-muted)]'
                      }`}
                    />
                  )}
                </React.Fragment>
              ))}
            </div>

            {/* Progress text */}
            <p className="text-center text-sm text-[var(--text-secondary)]">
              {t('surveys.step_of', { current: currentStep + 1, total: totalSteps })}
            </p>
          </div>
        )}

        {/* Step Content */}
        <Card className="p-6 border border-[var(--border-default)] mb-6">
          {/* Step Title */}
          {isMultiStep && currentStepData && (
            <div className="mb-6 pb-4 border-b border-[var(--border-default)]">
              <h2 className="text-lg font-semibold text-[var(--text-primary)]">
                {currentStepData.title}
              </h2>
              {currentStepData.description && (
                <p className="text-sm text-[var(--text-secondary)] mt-1">
                  {currentStepData.description}
                </p>
              )}
            </div>
          )}

          {/* Fields */}
          <div className="space-y-6">
            {currentStepData?.fields.map((field, index) => {
              const ids = getFieldIds(field);
              const fieldError = fieldErrors[field.field_key];
              const fieldLabel = (
                <>
                  <span className="inline-flex items-center justify-center w-6 h-6 rounded-full bg-[var(--accent-subtle)] text-[var(--accent-default)] text-xs ms-2">
                    {index + 1}
                  </span>
                  {field.label}
                  {field.is_required && <RequiredIndicator className="me-1" />}
                </>
              );

              return (
                <div key={field.id} className="space-y-2">
                  {GROUP_FIELD_TYPES.has(field.type) ? (
                    <div id={ids.labelId} className="block text-sm font-medium text-[var(--text-primary)]">
                      {fieldLabel}
                    </div>
                  ) : (
                    <label
                      id={ids.labelId}
                      htmlFor={ids.controlId}
                      className="block text-sm font-medium text-[var(--text-primary)]"
                    >
                      {fieldLabel}
                    </label>
                  )}
                  {field.description && (
                    <p id={ids.descriptionId} className="text-xs text-[var(--text-tertiary)] mb-2">
                      {field.description}
                    </p>
                  )}
                  {renderField(field)}
                  {fieldError && (
                    <p
                      id={ids.errorId}
                      role="alert"
                      className="flex items-center gap-1 text-sm text-[var(--status-danger)]"
                    >
                      <IconAlertCircle className="h-4 w-4 shrink-0" />
                      {fieldError}
                    </p>
                  )}
                </div>
              );
            })}
          </div>
        </Card>

        {/* Consent (فقط في الخطوة الأخيرة) */}
        {survey.consent_required && isLastStep && (
          <Card className="p-4 border border-[var(--border-default)] mb-6">
            <Checkbox
              checked={consentAccepted}
              onChange={(e) => setConsentAccepted(e.target.checked)}
              label={t('surveys.accept_terms')}
              description={survey.consent_text ?? undefined}
            />
          </Card>
        )}

        {/* Navigation Buttons */}
        <div className="flex items-center gap-3">
          {/* زر السابق */}
          {!isFirstStep && (
            <Button
              type="button"
              variant="outline"
              onClick={handlePrevious}
              className="flex-1"
              rightIcon={<IconChevronRight className="w-4 h-4" />}
            >
              {t('surveys.previous')}
            </Button>
          )}

          {/* زر التالي أو الإرسال */}
          <Button
            type="button"
            onClick={handleNext}
            disabled={pageState === 'submitting'}
            className="flex-1"
            leftIcon={
              isLastStep ? (
                pageState === 'submitting' ? undefined : (
                  <IconSend className="w-4 h-4" />
                )
              ) : (
                <IconChevronLeft className="w-4 h-4" />
              )
            }
          >
            {isLastStep
              ? pageState === 'submitting'
                ? t('surveys.submitting')
                : t('surveys.submit_answers')
              : t('surveys.next')}
          </Button>
        </div>

        {/* Footer */}
        <p className="text-center text-xs text-[var(--text-tertiary)] mt-6">
          <IconLock className="w-3 h-3 inline ms-1" />
          {t('surveys.answers_protected')}
        </p>
      </div>
    </div>
  );
};

export default PublicSurveyPage;
