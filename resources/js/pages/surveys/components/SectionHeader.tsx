import React from 'react';
import { useTranslation } from 'react-i18next';
import {IconStack2, IconFileText, IconPencil, IconTrash, IconCheck, IconX} from '@tabler/icons-react';
import { Input } from '@shared/ui';
import { SurveySection } from './types';

interface SectionHeaderProps {
  section: SurveySection | null;
  sectionsCount: number;
  fieldsCount: number;
  editingSectionId: number | null;
  sectionTitle: string;
  saving: boolean;
  onEditSection: (section: SurveySection) => void;
  onUpdateSection: () => void;
  onCancelSectionEdit: () => void;
  onDeleteSection: (sectionId: number) => void;
  onSectionTitleChange: (title: string) => void;
}

export const SectionHeader: React.FC<SectionHeaderProps> = ({
  section,
  sectionsCount,
  fieldsCount,
  editingSectionId,
  sectionTitle,
  saving,
  onEditSection,
  onUpdateSection,
  onCancelSectionEdit,
  onDeleteSection,
  onSectionTitleChange,
}) => {
  const { t } = useTranslation();
  if (section) {
    return (
      <div className="group flex items-center gap-3 p-3 rounded-lg bg-[var(--accent-subtle)] border border-[var(--accent-default)]/20 mb-3">
        <div className="w-8 h-8 rounded-lg bg-[var(--accent-default)] flex items-center justify-center">
          <IconStack2 className="w-4 h-4 text-[var(--text-inverse)]" />
        </div>
        {/* تعديل inline للقسم */}
        {editingSectionId === section.id ? (
          <div className="flex-1 flex items-center gap-2">
            <Input
              value={sectionTitle}
              onChange={(e) => onSectionTitleChange(e.target.value)}
              placeholder={t('surveys.section_title')}
              className="flex-1 h-8 text-sm"
              autoFocus
              onKeyDown={(e) => {
                if (e.key === 'Enter') onUpdateSection();
                if (e.key === 'Escape') onCancelSectionEdit();
              }}
            />
            <button
              onClick={onUpdateSection}
              disabled={saving}
              className="p-1 rounded bg-[var(--accent-default)] text-[var(--text-inverse)] hover:bg-[var(--accent-hover)]"
            >
              <IconCheck className="w-4 h-4" />
            </button>
            <button
              onClick={onCancelSectionEdit}
              className="p-1 rounded hover:bg-[var(--surface-muted)] text-[var(--text-tertiary)]"
            >
              <IconX className="w-4 h-4" />
            </button>
          </div>
        ) : (
          <>
            <div className="flex-1">
              <h3 className="font-semibold text-[var(--text-primary)]">{section.title}</h3>
              <span className="text-xs text-[var(--text-secondary)]">{fieldsCount} {t('surveys.field')}</span>
            </div>
            {/* أزرار تعديل وحذف القسم */}
            <div className="flex items-center gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
              <button
                onClick={() => onEditSection(section)}
                className="p-1 rounded hover:bg-[var(--surface-base)] text-[var(--text-tertiary)] hover:text-[var(--accent-default)]"
                title={t('surveys.edit_section')}
              >
                <IconPencil className="w-4 h-4" />
              </button>
              <button
                onClick={() => onDeleteSection(section.id)}
                className="p-1 rounded hover:bg-[var(--surface-base)] text-[var(--text-tertiary)] hover:text-[var(--status-danger)]"
                title={t('surveys.delete_section')}
              >
                <IconTrash className="w-4 h-4" />
              </button>
            </div>
          </>
        )}
      </div>
    );
  }

  // Unsectioned header
  if (sectionsCount > 0) {
    return (
      <div className="flex items-center gap-3 p-3 rounded-lg bg-[var(--surface-subtle)] border border-[var(--border-default)] mb-3">
        <div className="w-8 h-8 rounded-lg bg-[var(--surface-muted)] flex items-center justify-center">
          <IconFileText className="w-4 h-4 text-[var(--text-tertiary)]" />
        </div>
        <div className="flex-1">
          <h3 className="font-semibold text-[var(--text-secondary)]">{t('surveys.no_section')}</h3>
          <span className="text-xs text-[var(--text-tertiary)]">{fieldsCount} {t('surveys.field')}</span>
        </div>
      </div>
    );
  }

  return null;
};

export default SectionHeader;
