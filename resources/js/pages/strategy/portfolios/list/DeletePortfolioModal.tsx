import React from 'react';
import { useTranslation } from 'react-i18next';
import { DeleteConfirmationModal } from '@shared/ui';
import type { Portfolio } from './types';

interface DeletePortfolioModalProps {
  isOpen: boolean;
  portfolio: Portfolio | null;
  isDeleting: boolean;
  onClose: () => void;
  onConfirm: () => void;
}

const DeletePortfolioModal: React.FC<DeletePortfolioModalProps> = ({
  isOpen,
  portfolio,
  isDeleting,
  onClose,
  onConfirm,
}) => {
  const { t } = useTranslation();

  return (
    <DeleteConfirmationModal
      isOpen={isOpen}
      item={portfolio}
      title={t('strategy.portfolios.deletePortfolio')}
      itemName={portfolio?.name || ''}
      warningMessage={t('strategy.portfolios.deletePortfolioWarning')}
      isDeleting={isDeleting}
      onClose={onClose}
      onConfirm={onConfirm}
    />
  );
};

export default DeletePortfolioModal;
