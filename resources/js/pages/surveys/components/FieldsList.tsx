import React, { useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import {
  DndContext,
  closestCenter,
  KeyboardSensor,
  PointerSensor,
  useSensor,
  useSensors,
  DragEndEvent,
} from '@dnd-kit/core';
import { SortableContext, sortableKeyboardCoordinates, verticalListSortingStrategy } from '@dnd-kit/sortable';
import {IconPlus, IconStack2, IconCheck, IconX} from '@tabler/icons-react';
import { Button, Input, Card } from '@shared/ui';
import { SortableFieldItem } from './SortableFieldItem';
import { DroppableSection } from './DroppableSection';
import { SectionHeader } from './SectionHeader';
import { SurveyField, SurveySection } from './types';

interface FieldsListProps {
  fields: SurveyField[];
  sections: SurveySection[];
  editingFieldId: number | null;
  editingSectionId: number | null;
  sectionTitle: string;
  showSectionForm: boolean;
  saving: boolean;
  isInitialSurvey?: boolean;
  onEditField: (field: SurveyField) => void;
  onDeleteField: (fieldId: number) => void;
  onFieldDragEnd: (event: DragEndEvent) => void;
  onEditSection: (section: SurveySection) => void;
  onUpdateSection: () => void;
  onCancelSectionEdit: () => void;
  onDeleteSection: (sectionId: number) => void;
  onSectionTitleChange: (title: string) => void;
  onAddSection: () => void;
  onShowSectionForm: () => void;
}

export const FieldsList: React.FC<FieldsListProps> = ({
  fields,
  sections,
  editingFieldId,
  editingSectionId,
  sectionTitle,
  showSectionForm,
  saving,
  onEditField,
  onDeleteField,
  onFieldDragEnd,
  onEditSection,
  onUpdateSection,
  onCancelSectionEdit,
  onDeleteSection,
  onSectionTitleChange,
  onAddSection,
  onShowSectionForm,
}) => {
  const { t } = useTranslation();
  const sensors = useSensors(
    useSensor(PointerSensor, {
      activationConstraint: {
        distance: 8,
      },
    }),
    useSensor(KeyboardSensor, {
      coordinateGetter: sortableKeyboardCoordinates,
    })
  );

  // تجميع الحقول حسب الأقسام
  const fieldsBySection = useMemo(() => {
    const sortedFields = [...fields].sort((a, b) => a.order - b.order);
    const sortedSections = [...sections].sort((a, b) => a.order - b.order);

    const result: { section: SurveySection | null; fields: SurveyField[] }[] = [];

    // الحقول بدون قسم
    const unsectionedFields = sortedFields.filter((f) => !f.section_id);
    if (unsectionedFields.length > 0) {
      result.push({ section: null, fields: unsectionedFields });
    }

    // الحقول في الأقسام
    sortedSections.forEach((section) => {
      const sectionFields = sortedFields.filter((f) => f.section_id === section.id);
      result.push({ section, fields: sectionFields });
    });

    return result;
  }, [fields, sections]);

  if (fields.length === 0) {
    return (
      <Card className="p-6 border border-[var(--border-default)]">
        <div className="flex items-center justify-between mb-4">
          <h2 className="text-lg font-semibold text-[var(--text-primary)]">{t('surveys.survey_fields')} (0)</h2>
          <Button
            variant="outline"
            size="sm"
            leftIcon={<IconStack2 className="w-4 h-4" />}
            onClick={onShowSectionForm}
          >
            {t('surveys.new_section')}
          </Button>
        </div>

        <div className="text-center py-12">
          <div className="w-16 h-16 rounded-full bg-[var(--bg-secondary)] flex items-center justify-center mx-auto mb-4">
            <IconPlus className="w-8 h-8 text-[var(--text-secondary)]" />
          </div>
          <h3 className="text-lg font-medium text-[var(--text-primary)] mb-1">{t('surveys.no_fields_yet')}</h3>
          <p className="text-[var(--text-secondary)]">{t('surveys.start_adding_fields')}</p>
        </div>
      </Card>
    );
  }

  return (
    <Card className="p-6 border border-[var(--border-default)]">
      <div className="flex items-center justify-between mb-4">
        <h2 className="text-lg font-semibold text-[var(--text-primary)]">{t('surveys.survey_fields')} ({fields.length})</h2>
        <Button
          variant="outline"
          size="sm"
          leftIcon={<IconStack2 className="w-4 h-4" />}
          onClick={onShowSectionForm}
        >
          {t('surveys.new_section')}
        </Button>
      </div>

      <DndContext sensors={sensors} collisionDetection={closestCenter} onDragEnd={onFieldDragEnd}>
        <div className="space-y-6">
          {fieldsBySection.map(({ section, fields: sectionFields }) => (
            <div key={section?.id || 'unsectioned'}>
              <SectionHeader
                section={section}
                sectionsCount={sections.length}
                fieldsCount={sectionFields.length}
                editingSectionId={editingSectionId}
                sectionTitle={sectionTitle}
                saving={saving}
                onEditSection={onEditSection}
                onUpdateSection={onUpdateSection}
                onCancelSectionEdit={onCancelSectionEdit}
                onDeleteSection={onDeleteSection}
                onSectionTitleChange={onSectionTitleChange}
              />

              <DroppableSection sectionId={section?.id ?? null}>
                <SortableContext
                  items={sectionFields.map((f) => f.id!)}
                  strategy={verticalListSortingStrategy}
                >
                  {sectionFields.length === 0 && section && (
                    <p className="text-sm text-[var(--text-tertiary)] text-center py-4 border border-dashed border-[var(--border-default)] rounded-lg">
                      {t('surveys.no_fields_in_section')}
                    </p>
                  )}

                  {sectionFields.map((field) => (
                    <SortableFieldItem
                      key={field.id}
                      field={field}
                      editingFieldId={editingFieldId}
                      onEdit={onEditField}
                      onDelete={onDeleteField}
                    />
                  ))}
                </SortableContext>
              </DroppableSection>
            </div>
          ))}

          {/* نموذج إضافة قسم جديد */}
          {showSectionForm && (
            <div className="flex items-center gap-3 p-4 rounded-lg border-2 border-dashed border-[var(--accent-default)] bg-[var(--accent-subtle)]">
              <div className="w-8 h-8 rounded-lg bg-[var(--accent-default)] flex items-center justify-center">
                <IconStack2 className="w-4 h-4 text-[var(--text-inverse)]" />
              </div>
              <Input
                value={sectionTitle}
                onChange={(e) => onSectionTitleChange(e.target.value)}
                placeholder={t('surveys.new_section_title')}
                className="flex-1 h-9"
                autoFocus
                onKeyDown={(e) => {
                  if (e.key === 'Enter') onAddSection();
                  if (e.key === 'Escape') onCancelSectionEdit();
                }}
              />
              <Button
                onClick={onAddSection}
                disabled={saving || !sectionTitle.trim()}
                size="sm"
                leftIcon={<IconCheck className="w-4 h-4" />}
              >
                {t('common.add')}
              </Button>
              <Button
                variant="ghost"
                size="sm"
                onClick={onCancelSectionEdit}
                aria-label={t('common.cancel')}
              >
                <IconX className="w-4 h-4" />
              </Button>
            </div>
          )}
        </div>
      </DndContext>
    </Card>
  );
};

export default FieldsList;
