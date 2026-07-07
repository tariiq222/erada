import React, { useState, useEffect } from 'react';
import { Button, DatePicker, Input, Modal, ModalBody, ModalFooter, Select } from '@shared/ui';
import { RequiredIndicator } from '@shared/ui/RequiredIndicator';
import {IconPaperclip} from '@tabler/icons-react';
import { useToast } from '@shared/ui/Toast';
import { Expense, ExpenseFormData, EXPENSE_CATEGORIES } from './types';
import AttachmentUpload from './AttachmentUpload';

interface ExpenseFormModalProps {
  isOpen: boolean;
  onClose: () => void;
  projectId: number;
  expense: Expense | null;
  tasks: { id: number; title: string }[];
  onSubmit: (data: ExpenseFormData, attachment: File | null) => Promise<void>;
}

const ExpenseFormModal: React.FC<ExpenseFormModalProps> = ({
  isOpen,
  onClose,
  projectId,
  expense,
  tasks,
  onSubmit,
}) => {
  const { showToast } = useToast();
  const [submitting, setSubmitting] = useState(false);
  const [formData, setFormData] = useState<ExpenseFormData>({
    title: '',
    description: '',
    amount: '',
    category: 'other',
    expense_date: new Date().toISOString().split('T')[0],
    task_id: '',
    reference_number: '',
  });
  const [attachmentFile, setAttachmentFile] = useState<File | null>(null);
  const [attachmentPreview, setAttachmentPreview] = useState<string | null>(null);

  useEffect(() => {
    if (expense) {
      setFormData({
        title: expense.title,
        description: expense.description || '',
        amount: String(expense.amount),
        category: expense.category,
        expense_date: expense.expense_date,
        task_id: expense.task?.id ? String(expense.task.id) : '',
        reference_number: expense.reference_number || '',
      });
    } else {
      resetForm();
    }
  }, [expense, isOpen]);

  const resetForm = () => {
    setFormData({
      title: '',
      description: '',
      amount: '',
      category: 'other',
      expense_date: new Date().toISOString().split('T')[0],
      task_id: '',
      reference_number: '',
    });
    setAttachmentFile(null);
    setAttachmentPreview(null);
  };

  const handleFileChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (file) {
      const allowedTypes = ['application/pdf', 'image/jpeg', 'image/jpg', 'image/png'];
      if (!allowedTypes.includes(file.type)) {
        showToast('error', 'يُسمح فقط بملفات PDF و JPG و PNG', 'نوع ملف غير مدعوم');
        return;
      }
      if (file.size > 5 * 1024 * 1024) {
        showToast('error', 'حجم الملف يجب أن يكون أقل من 5 ميجابايت', 'حجم ملف كبير');
        return;
      }
      setAttachmentFile(file);
      if (file.type.startsWith('image/')) {
        const reader = new FileReader();
        reader.onloadend = () => setAttachmentPreview(reader.result as string);
        reader.readAsDataURL(file);
      } else {
        setAttachmentPreview(null);
      }
    }
  };

  const removeAttachment = () => {
    setAttachmentFile(null);
    setAttachmentPreview(null);
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setSubmitting(true);
    try {
      await onSubmit(formData, attachmentFile);
      resetForm();
      onClose();
    } catch {
      // Error handled by parent
    } finally {
      setSubmitting(false);
    }
  };

  const categoryOptionsForForm = Object.entries(EXPENSE_CATEGORIES).map(([key, label]) => ({
    value: key,
    label: label,
  }));

  const taskOptions = [
    { value: '', label: 'بدون ربط' },
    ...tasks.map((task) => ({
      value: String(task.id),
      label: task.title,
    })),
  ];

  return (
    <Modal
      open={isOpen}
      onClose={() => {
        onClose();
        resetForm();
      }}
      title={expense ? 'تعديل مصروف' : 'إضافة مصروف جديد'}
      size="md"
    >
      <form onSubmit={handleSubmit}>
        <ModalBody className="!py-3">
          <div className="space-y-3">
            {/* العنوان */}
            <div>
              <label className="block text-sm font-medium text-[var(--text-secondary)] mb-1">
                العنوان <RequiredIndicator />
              </label>
              <Input
                value={formData.title}
                onChange={(e) => setFormData({ ...formData, title: e.target.value })}
                placeholder="عنوان المصروف"
                required
              />
            </div>

            {/* المبلغ والتصنيف والتاريخ في صف واحد */}
            <div className="grid grid-cols-3 gap-3">
              <div>
                <label className="block text-sm font-medium text-[var(--text-secondary)] mb-1">
                  المبلغ <RequiredIndicator />
                </label>
                <Input
                  type="number"
                  step="0.01"
                  min="0.01"
                  value={formData.amount}
                  onChange={(e) => setFormData({ ...formData, amount: e.target.value })}
                  placeholder="0.00"
                  required
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-[var(--text-secondary)] mb-1">
                  التصنيف <RequiredIndicator />
                </label>
                <Select
                  options={categoryOptionsForForm}
                  value={formData.category}
                  onChange={(e) => setFormData({ ...formData, category: e.target.value })}
                  required
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-[var(--text-secondary)] mb-1">
                  التاريخ <RequiredIndicator />
                </label>
                <DatePicker
                  id="project-expense-date"
                  value={formData.expense_date}
                  onChange={(value) => setFormData({ ...formData, expense_date: value })}
                  required
                />
              </div>
            </div>

            {/* الوصف */}
            <div>
              <label className="block text-sm font-medium text-[var(--text-secondary)] mb-1">
                الوصف
              </label>
              <textarea
                value={formData.description}
                onChange={(e) => setFormData({ ...formData, description: e.target.value })}
                placeholder="وصف تفصيلي للمصروف (اختياري)"
                className="w-full px-3 py-2 border border-[var(--border-default)] rounded-lg focus:ring-2 focus:ring-[var(--accent-subtle)] focus:border-transparent resize-none text-sm"
                rows={2}
              />
            </div>

            {/* ربط بمهمة ورقم مرجعي */}
            <div className="grid grid-cols-2 gap-3">
              <div>
                <label className="block text-sm font-medium text-[var(--text-secondary)] mb-1">
                  ربط بمهمة
                </label>
                <Select
                  options={taskOptions}
                  value={formData.task_id}
                  onChange={(e) => setFormData({ ...formData, task_id: e.target.value })}
                  placeholder="بدون ربط"
                />
              </div>
              <div>
                <label className="block text-sm font-medium text-[var(--text-secondary)] mb-1">
                  رقم مرجعي
                </label>
                <Input
                  value={formData.reference_number}
                  onChange={(e) => setFormData({ ...formData, reference_number: e.target.value })}
                  placeholder="رقم الفاتورة أو الإيصال"
                />
              </div>
            </div>

            {/* رفع المرفقات */}
            <div>
              <label className="block text-sm font-medium text-[var(--text-secondary)] mb-1">
                <IconPaperclip className="inline-block h-4 w-4 ml-1" />
                مرفق (فاتورة / إيصال)
              </label>
              <AttachmentUpload
                projectId={projectId}
                attachmentFile={attachmentFile}
                attachmentPreview={attachmentPreview}
                expense={expense}
                onFileChange={handleFileChange}
                onRemove={removeAttachment}
              />
            </div>
          </div>
        </ModalBody>
        <ModalFooter>
          <Button
            type="button"
            variant="outline"
            onClick={() => {
              onClose();
              resetForm();
            }}
          >
            إلغاء
          </Button>
          <Button type="submit" disabled={submitting}>
            {submitting ? 'جاري الحفظ...' : expense ? 'تحديث' : 'إضافة'}
          </Button>
        </ModalFooter>
      </form>
    </Modal>
  );
};

export default ExpenseFormModal;
