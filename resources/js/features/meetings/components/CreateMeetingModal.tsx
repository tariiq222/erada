import React from 'react';
import { Modal } from '@shared/ui';
import { useTranslation } from 'react-i18next';
import type { DecidableAlias, Meeting } from '@features/meetings/types';
import MeetingForm from '@pages/strategy/meetings/MeetingForm';

interface Props {
  open: boolean;
  subject_type: DecidableAlias;
  subject_id: number;
  onClose: () => void;
  onCreated?: () => void;
}

const CreateMeetingModal: React.FC<Props> = ({ open, subject_type, subject_id, onClose, onCreated }) => {
  const { t } = useTranslation();

  const handleSuccess = (_meeting: Meeting) => {
    onCreated?.();
    onClose();
  };

  return (
    <Modal open={open} onClose={onClose} title={t('meetings.meeting.form.create_title')} size="lg">
      <MeetingForm
        mode="modal"
        prefill={{ subject_type, subject_id }}
        onSuccess={handleSuccess}
        onCancel={onClose}
      />
    </Modal>
  );
};

export default CreateMeetingModal;
