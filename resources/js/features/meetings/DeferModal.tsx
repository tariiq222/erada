import React, { useState } from 'react';
import { useTranslation } from 'react-i18next';
import {
  Alert,
  Button,
  DatePicker,
  Modal,
  ModalBody,
  ModalFooter,
  ModalHeader,
  Textarea,
} from '@shared/ui';
import { IconAlertTriangle, IconClock } from '@shared/ui/icons';
import { useToast } from '@shared/ui/Toast';
import { recommendationsApi } from './api';

export interface DeferModalProps {
  open: boolean;
  recommendationId: number;
  onClose: () => void;
  onDeferred?: () => void;
}

const DeferModal: React.FC<DeferModalProps> = ({
  open,
  recommendationId,
  onClose,
  onDeferred,
}) => {
  const { t } = useTranslation();
  const { showToast } = useToast();
  const [reason, setReason] = useState('');
  const [until, setUntil] = useState('');
  const [saving, setSaving] = useState(false);

  const trimmedReason = reason.trim();
  const missingReason = trimmedReason.length === 0;
  const missingDate = until.length === 0;
  const missingBoth = missingReason && missingDate;

  const submit = async () => {
    if (missingBoth) {
      showToast('error', t('meetings.recommendation.defer.missing_both'));
      return;
    }
    setSaving(true);
    try {
      await recommendationsApi.defer(recommendationId, {
        defer_reason: trimmedReason || null,
        deferred_until: until || null,
      });
      showToast('success', t('meetings.recommendation.messages.deferred'));
      onDeferred?.();
      onClose();
    } catch (err) {
      const msg = err instanceof Error ? err.message : t('common.error_occurred');
      showToast('error', msg);
    } finally {
      setSaving(false);
    }
  };

  return (
    <Modal open={open} onClose={onClose} size="md">
      <ModalHeader onClose={onClose}>
        <div className="flex items-center gap-2">
          <IconClock className="h-5 w-5 text-[var(--accent-default)]" />
          <h2 className="text-lg font-semibold text-[var(--text-primary)]">
            {t('meetings.recommendation.defer.title', { defaultValue: 'تأجيل القرار' })}
          </h2>
        </div>
      </ModalHeader>
      <ModalBody className="space-y-4">
        {missingBoth && (
          <Alert variant="warning" icon={<IconAlertTriangle className="h-4 w-4" />}>
            {t('meetings.recommendation.defer.warning', {
              defaultValue: 'تأجيل بدون سبب أو تاريخ — قد يدفن القرار',
            })}
          </Alert>
        )}

        <Textarea
          label={t('meetings.recommendation.defer.reason_label', { defaultValue: 'سبب التأجيل' })}
          value={reason}
          onChange={(e) => setReason(e.target.value)}
          rows={3}
          placeholder={t('meetings.recommendation.defer.reason_placeholder', {
            defaultValue: 'لماذا يتم تأجيل هذا القرار؟',
          })}
          hint={
            missingReason
              ? t('meetings.recommendation.defer.reason_missing_hint', {
                  defaultValue: 'يُفضّل كتابة سبب واضح',
                })
              : undefined
          }
        />

        <DatePicker
          label={t('meetings.recommendation.defer.until_label', { defaultValue: 'تاريخ الاستئناف' })}
          value={until}
          onChange={setUntil}
          hint={
            missingDate
              ? t('meetings.recommendation.defer.date_missing_hint', {
                  defaultValue: 'حدّد متى يُستأنف القرار',
                })
              : undefined
          }
        />
      </ModalBody>
      <ModalFooter>
        <Button variant="outline" onClick={onClose} disabled={saving}>
          {t('meetings.recommendation.form.cancel')}
        </Button>
        <Button onClick={submit} loading={saving} leftIcon={<IconClock className="h-4 w-4" />}>
          {t('meetings.recommendation.actions.defer')}
        </Button>
      </ModalFooter>
    </Modal>
  );
};

export default DeferModal;