/**
 * TriageModal — تصنيف طلب المشروع
 *
 * نقطة الاستخدام المقترحة:
 *   resources/js/pages/projects/ProjectsList.tsx
 *   — أضف زر «طلب جديد» في PageHeader يفتح هذا الموديل
 *   — عند onComplete، وجّه المستخدم لنموذج إنشاء المشروع بالنوع المناسب:
 *       type === 'development' → navigate('/projects/new?type=development')
 *       type === 'improvement' → navigate('/projects/new?type=improvement')
 *
 * Example usage:
 *   import { TriageModal } from '@pages/projects/triage/TriageModal';
 *
 *   <TriageModal
 *     open={showTriage}
 *     onClose={() => setShowTriage(false)}
 *     onComplete={(type, answers) => {
 *       setShowTriage(false);
 *       navigate(`/projects/new?type=${type}`);
 *     }}
 *   />
 */
import React, { useState, useCallback } from 'react';
import { useTranslation } from 'react-i18next';
import { Modal, ModalBody, ModalFooter, Button } from '@shared/ui';

// ─── Types ────────────────────────────────────────────────────────────────────

export interface TriageAnswers {
  q1: 'yes' | 'no' | null;
  q2: 'yes' | 'no' | null;
  q3: 'big' | 'small' | null;
}

export type TriageProjectType = 'development' | 'improvement';

export interface TriageModalProps {
  open: boolean;
  onClose: () => void;
  onComplete: (type: TriageProjectType, triageAnswers: TriageAnswers) => void;
}

// ─── Classification logic ──────────────────────────────────────────────────────

function classify(answers: TriageAnswers): TriageProjectType | null {
  const { q1, q2, q3 } = answers;

  if (q1 === 'yes') return 'improvement';

  if (q1 === 'no') {
    if (q2 === null || q3 === null) return null; // incomplete — wait
    // A new effort qualifies as a development project when it needs a dedicated
    // budget OR a team of 5+; otherwise it is an improvement.
    if (q2 === 'yes' || q3 === 'big') return 'development';
    return 'improvement';
  }

  return null;
}

// ─── Result content ────────────────────────────────────────────────────────────

type ResultContent = { titleKey: string; descKey: string };

const RESULT_CONTENT: Record<TriageProjectType, ResultContent> = {
  development: {
    titleKey: 'triage.result_new_title',
    descKey: 'triage.result_new_desc',
  },
  improvement: {
    titleKey: 'triage.result_improvement_title',
    descKey: 'triage.result_improvement_desc',
  },
};

// ─── ChoiceButton ─────────────────────────────────────────────────────────────

interface ChoiceButtonProps {
  label: string;
  selected: boolean;
  onClick: () => void;
}

const ChoiceButton: React.FC<ChoiceButtonProps> = ({ label, selected, onClick }) => (
  <button
    type="button"
    role="radio"
    aria-checked={selected}
    onClick={onClick}
    className={[
      'flex-1 inline-flex items-center justify-center min-h-[2.75rem] py-2.5 px-4 rounded-[var(--radius-md)] text-center text-sm font-medium cursor-pointer transition-colors duration-150 border-2',
      'focus-visible:outline-2 focus-visible:outline-[var(--border-focus)] focus-visible:outline-offset-2',
      selected
        ? 'border-[var(--accent-default)] bg-[var(--accent-subtle)] text-[var(--accent-hover)]'
        : 'border-[var(--border-default)] bg-[var(--surface-base)] text-[var(--text-primary)] hover:border-[var(--border-strong)]',
    ].join(' ')}
  >
    {label}
  </button>
);

// ─── QuestionBlock ────────────────────────────────────────────────────────────

interface Choice<T extends string> {
  value: T;
  label: string;
}

interface QuestionBlockProps<T extends string> {
  label: string;
  choices: [Choice<T>, Choice<T>];
  value: T | null;
  onChange: (v: T) => void;
}

function QuestionBlock<T extends string>({
  label,
  choices,
  value,
  onChange,
}: QuestionBlockProps<T>) {
  return (
    <div role="radiogroup" aria-label={label}>
      <p className="text-[var(--text-primary)] font-semibold text-sm text-center mb-4">{label}</p>
      <div className="flex flex-col gap-3 sm:flex-row">
        {choices.map((choice) => (
          <ChoiceButton
            key={choice.value}
            label={choice.label}
            selected={value === choice.value}
            onClick={() => onChange(choice.value)}
          />
        ))}
      </div>
    </div>
  );
}

// ─── ResultCard ───────────────────────────────────────────────────────────────

interface ResultCardProps {
  type: TriageProjectType;
  onContinue: () => void;
}

const ResultCard: React.FC<ResultCardProps> = ({ type, onContinue }) => {
  const { t } = useTranslation();
  const { titleKey, descKey } = RESULT_CONTENT[type];
  return (
    <div
      className={[
        'rounded-[var(--radius-md)] border border-[var(--border-default)]',
        'bg-[var(--surface-subtle)] p-4 space-y-3',
      ].join(' ')}
    >
      <p className="text-[var(--text-primary)] font-semibold text-sm">{t(titleKey)}</p>
      <p className="text-[var(--text-secondary)] text-sm leading-relaxed">{t(descKey)}</p>
      <div className="pt-1">
        <Button variant="primary" onClick={onContinue}>
          {t('triage.continue_button')}
        </Button>
      </div>
    </div>
  );
};

// ─── TriageModal ──────────────────────────────────────────────────────────────

export const TriageModal: React.FC<TriageModalProps> = ({ open, onClose, onComplete }) => {
  const { t } = useTranslation();
  const [answers, setAnswers] = useState<TriageAnswers>({ q1: null, q2: null, q3: null });

  const setQ1 = useCallback((v: 'yes' | 'no') => {
    setAnswers((prev) => ({
      q1: v,
      // reset downstream when switching back to 'yes' (or any change)
      q2: v === 'yes' ? null : prev.q2,
      q3: v === 'yes' ? null : prev.q3,
    }));
  }, []);

  const setQ2 = useCallback((v: 'yes' | 'no') => {
    setAnswers((prev) => ({ ...prev, q2: v }));
  }, []);

  const setQ3 = useCallback((v: 'big' | 'small') => {
    setAnswers((prev) => ({ ...prev, q3: v }));
  }, []);

  const handleClose = () => {
    setAnswers({ q1: null, q2: null, q3: null });
    onClose();
  };

  const projectType = classify(answers);

  const handleContinue = () => {
    if (!projectType) return;
    const snapshot = { ...answers };
    setAnswers({ q1: null, q2: null, q3: null });
    onComplete(projectType, snapshot);
  };

  return (
    <Modal open={open} onClose={handleClose} title={t('triage.title')} size="md">
      <ModalBody>
        <div className="space-y-6">
          {/* Q1 */}
          <QuestionBlock<'yes' | 'no'>
            label={t('triage.q1_label')}
            choices={[
              { value: 'yes', label: t('triage.q1_yes') },
              { value: 'no', label: t('triage.q1_no') },
            ]}
            value={answers.q1}
            onChange={setQ1}
          />

          {/* Q2 — visible only when q1 === 'no' */}
          {answers.q1 === 'no' && (
            <QuestionBlock<'yes' | 'no'>
              label={t('triage.q2_label')}
              choices={[
                { value: 'yes', label: t('triage.q2_yes') },
                { value: 'no', label: t('triage.q2_no') },
              ]}
              value={answers.q2}
              onChange={setQ2}
            />
          )}

          {/* Q3 — visible only when q1 === 'no' */}
          {answers.q1 === 'no' && (
            <QuestionBlock<'big' | 'small'>
              label={t('triage.q3_label')}
              choices={[
                { value: 'big', label: t('triage.q3_big') },
                { value: 'small', label: t('triage.q3_small') },
              ]}
              value={answers.q3}
              onChange={setQ3}
            />
          )}

          {/* Result card — shown automatically when classification is determined */}
          {projectType && (
            <ResultCard type={projectType} onContinue={handleContinue} />
          )}
        </div>
      </ModalBody>

      <ModalFooter>
        <Button variant="ghost" onClick={handleClose}>
          {t('common.cancel')}
        </Button>
      </ModalFooter>
    </Modal>
  );
};
