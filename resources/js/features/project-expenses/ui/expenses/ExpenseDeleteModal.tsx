import React from 'react';
import { Button, Modal, ModalBody, ModalFooter } from '@shared/ui';
import { Expense, formatCurrency } from './types';

interface ExpenseDeleteModalProps {
  isOpen: boolean;
  onClose: () => void;
  expense: Expense | null;
  onConfirm: () => Promise<void>;
  isDeleting: boolean;
}

const ExpenseDeleteModal: React.FC<ExpenseDeleteModalProps> = ({
  isOpen,
  onClose,
  expense,
  onConfirm,
  isDeleting,
}) => {
  return (
    <Modal
      open={isOpen}
      onClose={onClose}
      title="تأكيد الحذف"
      size="sm"
    >
      <ModalBody>
        <p className="text-[var(--text-secondary)]">
          هل أنت متأكد من حذف المصروف &quot;{expense?.title}&quot;؟
        </p>
        <p className="text-sm text-[var(--text-tertiary)] mt-2">
          المبلغ: {expense && formatCurrency(expense.amount)}
        </p>
      </ModalBody>
      <ModalFooter>
        <Button variant="outline" onClick={onClose}>
          إلغاء
        </Button>
        <Button
          variant="danger"
          onClick={onConfirm}
          disabled={isDeleting}
        >
          {isDeleting ? 'جاري الحذف...' : 'حذف'}
        </Button>
      </ModalFooter>
    </Modal>
  );
};

export default ExpenseDeleteModal;
