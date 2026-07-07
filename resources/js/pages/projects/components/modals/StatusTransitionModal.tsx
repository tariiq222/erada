import React, { useState, useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import {IconCircleCheck, IconEye, IconAlertCircle, IconAlertTriangle, IconMessage, IconBulb, IconTarget} from '@tabler/icons-react';
import { taskStatusLabels } from '../../constants';
import { RequiredIndicator } from '@shared/ui/RequiredIndicator';

export interface CompletionData {
  comment: string;
  challenges?: string;
  lessonsLearned?: string;
}

interface StatusTransitionModalProps {
  isOpen: boolean;
  onClose: () => void;
  onConfirm: (comment: string, completionData?: CompletionData) => void;
  taskTitle: string;
  newStatus: string;
  confirmationMessage: string;
  isCompleting: boolean;
  userRole?: 'super_admin' | 'project_manager' | 'member';
  isImprovement?: boolean;
}

const StatusTransitionModal: React.FC<StatusTransitionModalProps> = ({
  isOpen,
  onClose,
  onConfirm,
  taskTitle,
  newStatus,
  confirmationMessage,
  isCompleting,
  userRole = 'member',
  isImprovement = false,
}) => {
  const { t } = useTranslation();
  const titleId = React.useId();
  const [comment, setComment] = useState('');
  const [challenges, setChallenges] = useState('');
  const [lessonsLearned, setLessonsLearned] = useState('');

  useEffect(() => {
    if (!isOpen) return;
    const handleKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') handleClose();
    };
    document.addEventListener('keydown', handleKey);
    return () => document.removeEventListener('keydown', handleKey);
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [isOpen]);

  if (!isOpen) return null;

  // التعليق إلزامي عند النقل لقيد المراجعة
  const isMovingToReview = newStatus === 'in_review';
  const isCommentRequired = isMovingToReview;
  const isMember = userRole === 'member';
  // مشاريع التحسين: الدرس إلزامي عند الإكمال (منهجية PDCA)
  const isLessonsRequired = isCompleting && isImprovement;

  const handleConfirm = () => {
    if (isCommentRequired && !comment.trim()) {
      return;
    }
    if (isLessonsRequired && !lessonsLearned.trim()) {
      return;
    }

    if (isCompleting) {
      onConfirm(comment, {
        comment,
        challenges: challenges.trim() || undefined,
        lessonsLearned: lessonsLearned.trim() || undefined,
      });
    } else {
      onConfirm(comment);
    }

    setComment('');
    setChallenges('');
    setLessonsLearned('');
  };

  const handleClose = () => {
    setComment('');
    setChallenges('');
    setLessonsLearned('');
    onClose();
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center">
      {/* Backdrop */}
      <div data-testid="modal-backdrop" className="absolute inset-0 bg-[var(--surface-overlay)]" onClick={handleClose} />

      {/* Modal */}
      <div
        role="dialog"
        aria-modal="true"
        aria-labelledby={titleId}
        className={`relative bg-[var(--surface-base)] rounded-xl sm:rounded-2xl shadow-xl w-full mx-4 overflow-hidden ${isCompleting ? 'max-w-lg' : 'max-w-md'}`}
      >
        {/* Header */}
        <div className={`px-5 py-4 ${isCompleting ? 'bg-[var(--status-success-subtle)]' : isMovingToReview ? 'bg-[var(--status-warning-subtle)]' : 'bg-[var(--accent-subtle)]'}`}>
          <div className="flex items-center gap-3">
            <div className={`p-2 rounded-xl ${isCompleting ? 'bg-[var(--status-success-subtle)]' : isMovingToReview ? 'bg-[var(--status-warning-subtle)]' : 'bg-[var(--accent-subtle)]'}`}>
              {isCompleting ? (
                <IconCircleCheck className="h-5 w-5 text-[var(--status-success)]" />
              ) : isMovingToReview ? (
                <IconEye className="h-5 w-5 text-[var(--status-warning)]" />
              ) : (
                <IconAlertCircle className="h-5 w-5 text-[var(--accent-default)]" />
              )}
            </div>
            <div>
              <h3 id={titleId} className="text-base sm:text-lg font-semibold text-[var(--text-primary)]">{t('projects.confirm_status_change')}</h3>
              <p className="text-sm text-[var(--text-secondary)]">{t('projects.move_to')}: {taskStatusLabels[newStatus] || newStatus}</p>
            </div>
          </div>
        </div>

        {/* Body */}
        <div className={`p-5 space-y-4 ${isCompleting ? 'max-h-[60vh] overflow-y-auto' : ''}`}>
          <div className="p-3 bg-[var(--surface-subtle)] rounded-xl">
            <p className="text-sm text-[var(--text-tertiary)] mb-1">{t('projects.task_label')}:</p>
            <p className="font-medium text-[var(--text-primary)]">{taskTitle}</p>
          </div>

          <p className="text-[var(--text-secondary)]">{confirmationMessage}</p>

          {/* تنبيه للأعضاء عند النقل لقيد المراجعة */}
          {isMovingToReview && isMember && (
            <div className="p-3 bg-[var(--status-warning-subtle)] border border-[var(--status-warning-subtle)] rounded-xl">
              <div className="flex items-start gap-2">
                <IconAlertTriangle className="h-5 w-5 text-[var(--status-warning)] shrink-0 mt-0" />
                <div>
                  <p className="text-sm font-medium text-[var(--status-warning)]">{t('projects.important_notice')}</p>
                  <p className="text-sm text-[var(--status-warning)] mt-1">
                    {t('projects.review_warning_message')}
                  </p>
                </div>
              </div>
            </div>
          )}

          {/* حقول الإكمال */}
          {isCompleting && (
            <>
              {/* التحديات وكيف تم حلها — تُخفى في مشاريع التحسين (منهجية PDCA) */}
              {!isImprovement && (
                <div>
                  <label className="block text-sm font-medium text-[var(--text-secondary)] mb-2">
                    <IconTarget className="h-4 w-4 inline-block ml-1" />
                    {t('projects.challenges_and_solutions')}
                  </label>
                  <textarea
                    value={challenges}
                    onChange={(e) => setChallenges(e.target.value)}
                    placeholder={t('projects.challenges_placeholder')}
                    className="w-full px-3 py-2 border border-[var(--border-default)] rounded-xl focus:ring-2 focus:ring-[var(--status-success-subtle)] focus:border-[var(--status-success)] transition-colors resize-none"
                    rows={3}
                    dir="auto"
                  />
                </div>
              )}

              {/* الدرس المستفاد / الدرس وقرار التعميم */}
              <div>
                <label className="block text-sm font-medium text-[var(--text-secondary)] mb-2">
                  <IconBulb className="h-4 w-4 inline-block ml-1" />
                  <span>
                    {isImprovement ? t('projects.lesson_and_standardize') : t('projects.lessons_learned')}
                    {isLessonsRequired && <RequiredIndicator />}
                  </span>
                </label>
                <textarea
                  value={lessonsLearned}
                  onChange={(e) => setLessonsLearned(e.target.value)}
                  placeholder={t('projects.lessons_learned_placeholder')}
                  className={`w-full px-3 py-2 border rounded-xl focus:ring-2 transition-colors resize-none ${
                    isLessonsRequired && !lessonsLearned.trim()
                      ? 'border-[var(--status-warning)] focus:ring-[var(--status-warning)]/20 focus:border-[var(--status-warning)]'
                      : 'border-[var(--border-default)] focus:ring-[var(--status-success-subtle)] focus:border-[var(--status-success)]'
                  }`}
                  rows={3}
                  dir="auto"
                />
                {isLessonsRequired && !lessonsLearned.trim() && (
                  <p className="text-xs text-[var(--status-warning-text)] mt-1">{t('projects.lesson_required')}</p>
                )}
              </div>
            </>
          )}

          {/* Comment Input - للمراجعة فقط */}
          {isMovingToReview && (
            <div>
              <label className="block text-sm font-medium text-[var(--text-secondary)] mb-2">
                <IconMessage className="h-4 w-4 inline-block ml-1" />
                <span>{isImprovement ? t('projects.what_was_done') : t('projects.review_reason')} <RequiredIndicator /></span>
              </label>
              <textarea
                value={comment}
                onChange={(e) => setComment(e.target.value)}
                placeholder={t('projects.review_reason_placeholder')}
                className={`w-full px-3 py-2 border rounded-xl focus:ring-2 transition-colors resize-none ${
                  !comment.trim()
                    ? 'border-[var(--status-warning)] focus:ring-[var(--status-warning)]/20 focus:border-[var(--status-warning)]'
                    : 'border-[var(--border-default)] focus:ring-[var(--border-focus)]/20 focus:border-[var(--border-focus)]'
                }`}
                rows={3}
                dir="auto"
              />
              {!comment.trim() && (
                <p className="text-xs text-[var(--status-warning-text)] mt-1">{t('projects.review_reason_required')}</p>
              )}
            </div>
          )}
        </div>

        {/* Footer */}
        <div className="flex items-center justify-end gap-3 px-5 py-4 border-t border-[var(--border-default)]">
          <button
            onClick={handleClose}
            className="px-4 py-2 text-[var(--text-secondary)] hover:bg-[var(--surface-subtle)] rounded-lg transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--accent-default)]"
          >
            {t('common.cancel')}
          </button>
          <button
            onClick={handleConfirm}
            disabled={(isCommentRequired && !comment.trim()) || (isLessonsRequired && !lessonsLearned.trim())}
            className={`px-4 py-2 text-[var(--text-inverse)] rounded-lg transition-colors disabled:opacity-50 disabled:cursor-not-allowed focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--accent-default)] focus-visible:ring-offset-2 ${
              isCompleting
                ? 'bg-[var(--status-success)] hover:bg-[var(--status-success)]'
                : isMovingToReview
                ? 'bg-[var(--status-warning)] hover:bg-[var(--status-warning)]'
                : 'bg-[var(--accent-default)] hover:bg-[var(--accent-hover)]'
            }`}
          >
            {isCompleting ? t('projects.close_task_permanently') : isMovingToReview ? t('projects.send_for_review') : t('common.confirm')}
          </button>
        </div>
      </div>
    </div>
  );
};

export default StatusTransitionModal;
