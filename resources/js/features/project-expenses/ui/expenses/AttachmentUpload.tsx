import React from 'react';
import {IconX, IconUpload, IconFileText, IconPhoto, IconEye} from '@tabler/icons-react';
import { IconButton } from '@shared/ui/IconButton';
import { Expense } from './types';

interface AttachmentUploadProps {
  projectId: number;
  attachmentFile: File | null;
  attachmentPreview: string | null;
  expense: Expense | null;
  onFileChange: (e: React.ChangeEvent<HTMLInputElement>) => void;
  onRemove: () => void;
}

const AttachmentUpload: React.FC<AttachmentUploadProps> = ({
  projectId,
  attachmentFile,
  attachmentPreview,
  expense,
  onFileChange,
  onRemove,
}) => {
  if (!attachmentFile && !expense?.attachment_path) {
    return (
      <label className="flex flex-col items-center justify-center w-full h-24 border-2 border-[var(--border-default)] border-dashed rounded-lg cursor-pointer bg-[var(--surface-subtle)] hover:bg-[var(--surface-muted)] transition-colors">
        <div className="flex flex-col items-center justify-center py-2">
          <IconUpload className="h-6 w-6 text-[var(--text-tertiary)] mb-1" />
          <p className="text-xs text-[var(--text-tertiary)]">اضغط لرفع ملف أو اسحبه هنا</p>
          <p className="text-[10px] text-[var(--text-tertiary)] mt-0">PDF, JPG, PNG (حد أقصى 5MB)</p>
        </div>
        <input
          type="file"
          className="hidden"
          accept=".pdf,.jpg,.jpeg,.png"
          onChange={onFileChange}
        />
      </label>
    );
  }

  return (
    <div className="flex items-center gap-3 p-3 bg-[var(--surface-subtle)] rounded-lg border border-[var(--border-default)]">
      {attachmentPreview ? (
        <img
          src={attachmentPreview}
          alt="معاينة"
          className="h-12 w-12 object-cover rounded-lg border"
        />
      ) : attachmentFile?.type === 'application/pdf' || expense?.attachment_path?.endsWith('.pdf') ? (
        <div className="h-12 w-12 flex items-center justify-center bg-[var(--status-danger-subtle)] rounded-lg border border-[var(--status-danger-subtle)]">
          <IconFileText className="h-6 w-6 text-[var(--status-danger)]" />
        </div>
      ) : expense?.attachment_path ? (
        <img
          src={`/api/projects/${projectId}/expenses/${expense.id}/attachment`}
          alt="معاينة"
          className="h-12 w-12 object-cover rounded-lg border"
        />
      ) : (
        <div className="h-12 w-12 flex items-center justify-center bg-[var(--accent-subtle)] rounded-lg border border-[var(--accent-subtle)]">
          <IconPhoto className="h-6 w-6 text-[var(--accent-default)]" />
        </div>
      )}

      <div className="flex-1 min-w-0">
        <p className="text-sm font-medium text-[var(--text-primary)] truncate">
          {attachmentFile?.name || expense?.attachment_path?.split('/').pop()}
        </p>
        <p className="text-xs text-[var(--text-tertiary)]">
          {attachmentFile ? `${(attachmentFile.size / 1024).toFixed(1)} KB` : 'ملف مرفق'}
        </p>
      </div>

      <div className="flex items-center gap-1">
        {expense?.attachment_path && !attachmentFile && (
          <a
            href={`/api/projects/${projectId}/expenses/${expense.id}/attachment`}
            target="_blank"
            rel="noopener noreferrer"
            className="p-1 text-[var(--text-tertiary)] hover:text-[var(--accent-default)] hover:bg-[var(--accent-subtle)] rounded-lg transition-colors"
            title="عرض الملف"
          >
            <IconEye className="h-4 w-4" />
          </a>
        )}
        <IconButton
          variant="danger"
          size="sm"
          onClick={onRemove}
          title="إزالة الملف"
        >
          <IconX className="h-4 w-4" />
        </IconButton>
      </div>
    </div>
  );
};

export default AttachmentUpload;
