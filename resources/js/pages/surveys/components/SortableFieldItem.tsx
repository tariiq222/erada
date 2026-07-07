import React from 'react';
import { useTranslation } from 'react-i18next';
import { useSortable } from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import {IconGripVertical, IconPencil, IconTrash, IconDatabase} from '@tabler/icons-react';
import { Button } from '@shared/ui';
import { IconButton } from '@shared/ui/IconButton';
import { RequiredIndicator } from '@shared/ui/RequiredIndicator';
import { SurveyField, fieldTypes } from './types';

interface SortableFieldItemProps {
  field: SurveyField;
  editingFieldId: number | null;
  onEdit: (field: SurveyField) => void;
  onDelete: (fieldId: number) => void;
}

export const SortableFieldItem: React.FC<SortableFieldItemProps> = ({
  field,
  editingFieldId,
  onEdit,
  onDelete,
}) => {
  const { t } = useTranslation();
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({
    id: field.id!,
  });

  const style = {
    transform: CSS.Transform.toString(transform),
    transition,
    opacity: isDragging ? 0.5 : 1,
  };

  return (
    <div
      ref={setNodeRef}
      style={style}
      className="group flex items-center gap-3 p-4 rounded-lg border border-[var(--border-default)] bg-[var(--surface-secondary)] hover:bg-[var(--surface-primary)] transition-colors"
    >
      {/* Drag handle */}
      <button
        {...attributes}
        {...listeners}
        className="cursor-grab active:cursor-grabbing p-1 rounded hover:bg-[var(--surface-muted)] text-[var(--text-tertiary)]"
      >
        <IconGripVertical className="w-4 h-4" />
      </button>

      {/* Field number */}
      <div className="w-7 h-7 flex items-center justify-center rounded-full bg-[var(--accent-default)]/10 text-[var(--accent-default)] text-sm font-medium">
        {field.order}
      </div>

      {/* Field info */}
      <div className="flex-1 min-w-0">
        <div className="flex items-center gap-2 mb-1">
          <span className="font-medium text-[var(--text-primary)]">
            {field.label}
            {field.is_required && <RequiredIndicator className="mr-1" />}
          </span>
          {field.config?.mapped_to && (
            <span className="text-xs text-[var(--accent-default)] bg-[var(--accent-default)]/10 px-1 py-0 rounded flex items-center gap-1">
              <IconDatabase className="w-3 h-3" />
              {field.config.mapped_to.table}
            </span>
          )}
        </div>
        <div className="flex items-center gap-2">
          <code className="text-xs bg-[var(--bg-tertiary)] px-1 py-0 rounded font-mono">{field.field_key}</code>
          <span className="text-xs text-[var(--text-secondary)]">
            {fieldTypes.find((t) => t.value === field.type)?.label || field.type}
          </span>
        </div>
        {field.description && (
          <p className="text-sm text-[var(--text-secondary)] mt-1 line-clamp-1">{field.description}</p>
        )}
      </div>

      {/* Edit & Delete buttons */}
      <div className="flex items-center gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
        <Button
          variant="ghost"
          size="sm"
          onClick={() => onEdit(field)}
          className={`text-[var(--text-tertiary)] hover:text-[var(--accent-default)] hover:bg-[var(--accent-subtle)] ${
            editingFieldId === field.id ? 'opacity-100 text-[var(--accent-default)] bg-[var(--accent-subtle)]' : ''
          }`}
          title={t('common.edit')}
        >
          <IconPencil className="w-4 h-4" />
        </Button>
        <IconButton
          variant="dangerStrong"
          size="md"
          onClick={() => onDelete(field.id!)}
          title={t('common.delete')}
        >
          <IconTrash className="w-4 h-4" />
        </IconButton>
      </div>
    </div>
  );
};

export default SortableFieldItem;
