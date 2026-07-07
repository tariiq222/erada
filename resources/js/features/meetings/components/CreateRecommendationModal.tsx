import React from 'react';
import { Modal, ModalBody } from '@shared/ui';
import { useTranslation } from 'react-i18next';
import RecommendationForm from '@pages/strategy/meetings/recommendations/RecommendationForm';

interface Props {
  open: boolean;
  decision_id: number;
  onClose: () => void;
  onCreated?: () => void;
}

const CreateRecommendationModal: React.FC<Props> = ({ open, decision_id, onClose, onCreated }) => {
  const { t } = useTranslation();
  return (
    <Modal open={open} onClose={onClose} title={t('meetings.recommendation.form.create_title')} size="lg">
      <ModalBody>
        <RecommendationForm
          mode="modal"
          prefill={{ decision_id }}
          onSuccess={() => {
            onCreated?.();
            onClose();
          }}
          onCancel={onClose}
        />
      </ModalBody>
    </Modal>
  );
};

export default CreateRecommendationModal;
