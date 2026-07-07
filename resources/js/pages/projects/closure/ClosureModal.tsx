/**
 * ClosureModal — نموذج إغلاق المشروع (متفرّع حسب النوع)
 *
 * نقطة الربط المقترحة:
 *   resources/js/pages/projects/ProjectDetail.tsx (أو الصفحة الخاصة بعرض المشروع)
 *   — أضف زر «إغلاق المشروع» في قائمة الإجراءات أو header المشروع
 *   — يظهر الزر فقط للحالات القابلة للإغلاق (in_progress, on_hold)
 *   — عند onComplete، أرسل closureData إلى API ثم حدّث الحالة إلى completed:
 *
 *   import { ClosureModal } from '@pages/projects/closure/ClosureModal';
 *
 *   <ClosureModal
 *     open={showClosure}
 *     project={{ type: project.type, name: project.name }}
 *     onClose={() => setShowClosure(false)}
 *     onComplete={(data) => {
 *       setShowClosure(false);
 *       api.patch(`/projects/${project.id}/close`, data);
 *     }}
 *   />
 */

import React, { useState, useCallback } from 'react';
import { useTranslation } from 'react-i18next';
import { Modal, ModalBody, ModalFooter, Button, Textarea, Input, Select, type SelectOption } from '@shared/ui';
import { RequiredIndicator } from '@shared/ui/RequiredIndicator';

// ─── Types ────────────────────────────────────────────────────────────────────

export type ProjectType = 'development' | 'improvement';

/** حالة تحقق الهدف — للمشاريع التحسينية فقط */
export type AchievementStatus = 'achieved' | 'partial' | 'not_achieved';

/**
 * بيانات الإغلاق المشتركة بين النوعين.
 * achievement_status مطلوب لكل المشاريع (يطابق ما يفرضه الـ API عند الإغلاق).
 */
export interface BaseClosureData {
  lessons_learned: string;
  outcome_summary: string;
  achievement_status: AchievementStatus;
}

/** بيانات الإغلاق الخاصة بالمشاريع التحسينية */
export interface ImprovementClosureData extends BaseClosureData {
  sustainability_plan: string;
  achievement_percentage: number;
}

/** النوع النهائي لـ closureData — يتفرّع حسب نوع المشروع */
export type ClosureData<T extends ProjectType = ProjectType> =
  T extends 'improvement' ? ImprovementClosureData : BaseClosureData;

export interface ClosureModalProps {
  open: boolean;
  project: {
    type: 'development' | 'improvement';
    name?: string;
  };
  onClose: () => void;
  onComplete: (closureData: ClosureData) => void;
  /** Number of not-yet-completed tasks; shows a non-blocking warning when > 0. */
  openTasksCount?: number;
}

// ─── Constants ────────────────────────────────────────────────────────────────

const ACHIEVEMENT_STATUS_KEYS: Record<AchievementStatus, { value: AchievementStatus; labelKey: string }> = {
  achieved: { value: 'achieved', labelKey: 'closure.achievement_status_achieved' },
  partial: { value: 'partial', labelKey: 'closure.achievement_status_partial' },
  not_achieved: { value: 'not_achieved', labelKey: 'closure.achievement_status_not_achieved' },
};

const ACHIEVEMENT_STATUS_ORDER: AchievementStatus[] = ['achieved', 'partial', 'not_achieved'];

// ─── Helpers ──────────────────────────────────────────────────────────────────

function createInitialState() {
  return {
    lessons_learned: '',
    outcome_summary: '',
    sustainability_plan: '',
    achievement_percentage: '' as string | number,
    achievement_status: '' as AchievementStatus | '',
  };
}

type FormState = ReturnType<typeof createInitialState>;

function isBaseValid(state: FormState): boolean {
  return (
    state.lessons_learned.trim().length > 0 &&
    state.outcome_summary.trim().length > 0 &&
    !!state.achievement_status
  );
}

function isImprovementValid(state: FormState): boolean {
  if (!isBaseValid(state)) return false;
  if (state.sustainability_plan.trim().length === 0) return false;
  const pct = Number(state.achievement_percentage);
  if (isNaN(pct) || pct < 0 || pct > 100) return false;
  return true;
}

// ─── SectionLabel ─────────────────────────────────────────────────────────────

const SectionLabel: React.FC<{ children: React.ReactNode; required?: boolean }> = ({
  children,
  required,
}) => (
  <p className="text-sm font-medium text-[var(--text-secondary)] mb-1">
    {children}
    {required && <RequiredIndicator className="ms-1" />}
  </p>
);

// ─── FieldNote ────────────────────────────────────────────────────────────────

const FieldNote: React.FC<{ children: React.ReactNode }> = ({ children }) => (
  <p className="mt-1 text-xs text-[var(--text-tertiary)]">{children}</p>
);

// ─── SectionDivider ───────────────────────────────────────────────────────────

const SectionDivider: React.FC<{ label: string }> = ({ label }) => (
  <div className="flex items-center gap-3 py-1">
    <div className="flex-1 h-px bg-[var(--border-default)]" />
    <span className="text-xs font-medium text-[var(--text-tertiary)] shrink-0">{label}</span>
    <div className="flex-1 h-px bg-[var(--border-default)]" />
  </div>
);

// ─── ClosureModal ─────────────────────────────────────────────────────────────

export const ClosureModal: React.FC<ClosureModalProps> = ({
  open,
  project,
  onClose,
  onComplete,
  openTasksCount = 0,
}) => {
  const { t } = useTranslation();
  const [form, setForm] = useState<FormState>(createInitialState);
  const [submitted, setSubmitted] = useState(false);

  const isImprovement = project.type === 'improvement';
  const isValid = isImprovement ? isImprovementValid(form) : isBaseValid(form);

  const achievementStatusOptions: SelectOption[] = ACHIEVEMENT_STATUS_ORDER.map((status) => ({
    value: ACHIEVEMENT_STATUS_KEYS[status].value,
    label: t(ACHIEVEMENT_STATUS_KEYS[status].labelKey),
  }));

  // ── field setters ──────────────────────────────────────────────────────────

  const setField = useCallback(
    <K extends keyof FormState>(key: K, value: FormState[K]) => {
      setForm((prev) => ({ ...prev, [key]: value }));
    },
    []
  );

  // ── reset & close ──────────────────────────────────────────────────────────

  const handleClose = useCallback(() => {
    setForm(createInitialState());
    setSubmitted(false);
    onClose();
  }, [onClose]);

  // ── submit ─────────────────────────────────────────────────────────────────

  const handleSubmit = useCallback(() => {
    setSubmitted(true);
    if (!isValid) return;

    const base: BaseClosureData = {
      lessons_learned: form.lessons_learned.trim(),
      outcome_summary: form.outcome_summary.trim(),
      achievement_status: form.achievement_status as AchievementStatus,
    };

    let closureData: ClosureData;
    if (isImprovement) {
      const improvementData: ImprovementClosureData = {
        ...base,
        sustainability_plan: form.sustainability_plan.trim(),
        achievement_percentage: Number(form.achievement_percentage),
      };
      closureData = improvementData;
    } else {
      closureData = base;
    }

    setForm(createInitialState());
    setSubmitted(false);
    onComplete(closureData);
  }, [form, isImprovement, isValid, onComplete]);

  // ── field-level validation messages (only after first submit attempt) ──────

  const showError = submitted;
  const lessonsError =
    showError && !form.lessons_learned.trim()
      ? t('closure.lessons_learned_required')
      : undefined;
  const outcomeError =
    showError && !form.outcome_summary.trim()
      ? t('closure.outcome_summary_required')
      : undefined;
  const sustainabilityError =
    showError && isImprovement && !form.sustainability_plan.trim()
      ? t('closure.sustainability_required')
      : undefined;
  const pctValue = Number(form.achievement_percentage);
  const percentageError =
    showError && isImprovement
      ? form.achievement_percentage === ''
        ? t('closure.achievement_percentage_required')
        : isNaN(pctValue) || pctValue < 0 || pctValue > 100
          ? t('closure.achievement_percentage_range')
          : undefined
      : undefined;
  const statusError =
    showError && !form.achievement_status
      ? t('closure.achievement_status_required')
      : undefined;

  // ─────────────────────────────────────────────────────────────────────────

  const projectLabel = project.name
    ? t('closure.title_with_name', { name: project.name })
    : t('closure.title_default');

  return (
    <Modal open={open} onClose={handleClose} title={projectLabel} size="md">
      <ModalBody>
        <div className="space-y-5">
          {/* Non-blocking warning: project still has open tasks */}
          {openTasksCount > 0 && (
            <div
              role="status"
              className="rounded-lg border border-[var(--status-warning-subtle)] bg-[var(--status-warning-subtle)] px-4 py-3 text-sm text-[var(--status-warning-text)]"
            >
              {t('closure.open_tasks_warning', { n: openTasksCount })}
            </div>
          )}

          {/* ── المشترك: الدروس المستفادة ────────────────────── */}
          <div>
            <Textarea
              label={t('closure.lessons_learned_label')}
              required
              rows={3}
              placeholder={t('closure.lessons_learned_placeholder')}
              value={form.lessons_learned}
              onChange={(e) => setField('lessons_learned', e.target.value)}
              error={lessonsError}
            />
            <FieldNote>
              {t('closure.lessons_learned_note')}
            </FieldNote>
          </div>

          {/* ── المشترك: ملخص النتيجة ────────────────────────── */}
          <div>
            <Textarea
              label={t('closure.outcome_summary_label')}
              required
              rows={3}
              placeholder={t('closure.outcome_summary_placeholder')}
              value={form.outcome_summary}
              onChange={(e) => setField('outcome_summary', e.target.value)}
              error={outcomeError}
            />
          </div>

          {/* ── المشترك: حالة التحقق (مطلوبة لكل المشاريع) ─────── */}
          <div>
            <Select
              label={t('closure.achievement_status_label')}
              required
              options={achievementStatusOptions}
              placeholder={t('closure.achievement_status_placeholder')}
              value={form.achievement_status}
              onChange={(e) =>
                setField('achievement_status', e.target.value as AchievementStatus | '')
              }
              error={statusError}
            />
          </div>

          {/* ── التحسيني فقط ─────────────────────────────────── */}
          {isImprovement && (
            <>
              <SectionDivider label={t('closure.section_improvement_only')} />

              {/* خطة الاستدامة (Control) */}
              <div>
                <Textarea
                  label={t('closure.sustainability_label')}
                  required
                  rows={3}
                  placeholder={t('closure.sustainability_placeholder')}
                  value={form.sustainability_plan}
                  onChange={(e) => setField('sustainability_plan', e.target.value)}
                  error={sustainabilityError}
                />
                <FieldNote>
                  {t('closure.sustainability_note')}
                </FieldNote>
              </div>

              {/* نسبة تحقق الهدف */}
              <div>
                <SectionLabel required>{t('closure.achievement_percentage_label')}</SectionLabel>
                <Input
                  type="number"
                  min={0}
                  max={100}
                  step={1}
                  placeholder={t('closure.achievement_percentage_placeholder')}
                  value={String(form.achievement_percentage)}
                  onChange={(e) => setField('achievement_percentage', e.target.value)}
                  error={percentageError}
                  aria-label={t('closure.achievement_percentage_aria_label')}
                />
                <FieldNote>
                  {t('closure.achievement_percentage_note')}
                </FieldNote>
              </div>
            </>
          )}
        </div>
      </ModalBody>

      <ModalFooter>
        <Button variant="ghost" onClick={handleClose}>
          {t('common.cancel')}
        </Button>
        <Button
          variant="primary"
          onClick={handleSubmit}
          disabled={submitted && !isValid}
        >
          {t('closure.submit_button')}
        </Button>
      </ModalFooter>
    </Modal>
  );
};
