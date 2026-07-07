import React from 'react';
import { useTranslation } from 'react-i18next';
import { Button, Input, Select, Textarea, Checkbox } from '@shared/ui';
import { RequiredIndicator } from '@shared/ui/RequiredIndicator';
import {IconPlus, IconTrash, IconPencil, IconDatabase, IconLink} from '@tabler/icons-react';
import {
  SurveyField,
  SurveySection,
  TargetModel,
  FieldOption,
  fieldTypes,
  hasOptions,
} from './types';

interface FieldFormProps {
  newField: Omit<SurveyField, 'id' | 'order'>;
  setNewField: React.Dispatch<React.SetStateAction<Omit<SurveyField, 'id' | 'order'>>>;
  options: FieldOption[];
  setOptions: React.Dispatch<React.SetStateAction<FieldOption[]>>;
  editingFieldId: number | null;
  sections: SurveySection[];
  targetModels: Record<string, TargetModel>;
  fieldSource: 'new' | 'existing';
  selectedTargetModel: string;
  selectedColumn: string;
  isInitialSurvey: boolean;
  saving: boolean;
  fields: SurveyField[];
  onFieldSourceChange: (source: 'new' | 'existing') => void;
  onTargetModelChange: (model: string) => void;
  onColumnSelect: (column: string) => void;
  onAddField: () => void;
  onUpdateField: () => void;
  onCancelEdit: () => void;
}

export const FieldForm: React.FC<FieldFormProps> = ({
  newField,
  setNewField,
  options,
  setOptions,
  editingFieldId,
  sections,
  targetModels,
  fieldSource,
  selectedTargetModel,
  selectedColumn,
  isInitialSurvey,
  saving,
  fields,
  onFieldSourceChange,
  onTargetModelChange,
  onColumnSelect,
  onAddField,
  onUpdateField,
  onCancelEdit,
}) => {
  const { t } = useTranslation();
  const generateFieldKey = (label: string) => {
    return label
      .toLowerCase()
      .replace(/[^\w\s]/gi, '')
      .replace(/\s+/g, '_')
      .substring(0, 50);
  };

  const handleLabelChange = (value: string) => {
    setNewField((prev) => ({
      ...prev,
      label: value,
      field_key: prev.field_key || generateFieldKey(value),
      name: prev.name || generateFieldKey(value),
    }));
  };

  const addOption = () => {
    setOptions([...options, { label: '', value: '' }]);
  };

  const removeOption = (index: number) => {
    setOptions(options.filter((_, i) => i !== index));
  };

  const updateOption = (index: number, field: 'label' | 'value', value: string) => {
    const updated = [...options];
    updated[index][field] = value;
    if (field === 'label' && !updated[index].value) {
      updated[index].value = generateFieldKey(value);
    }
    setOptions(updated);
  };

  // الحصول على الأعمدة غير المستخدمة
  const getAvailableColumns = () => {
    if (!selectedTargetModel || !targetModels[selectedTargetModel]) return [];

    const usedKeys = fields.map((f) => f.field_key);
    const columns = targetModels[selectedTargetModel].columns;

    return Object.entries(columns)
      .filter(([key]) => !usedKeys.includes(key))
      .map(([key, col]) => ({
        value: key,
        label: `${col.label} (${key})${col.required ? ' *' : ''}`,
      }));
  };

  const targetModelOptions = Object.entries(targetModels).map(([key, model]) => ({
    value: key,
    label: model.label,
  }));

  const sectionOptions = [
    { value: '', label: t('surveys.no_section') },
    ...sections.map((s) => ({ value: String(s.id), label: s.title })),
  ];

  return (
    <div className="space-y-4">
      {/* اختيار القسم */}
      {sections.length > 0 && (
        <div>
          <label className="block text-sm font-medium text-[var(--text-primary)] mb-1">{t('surveys.section')}</label>
          <Select
            value={newField.section_id ? String(newField.section_id) : ''}
            onChange={(e) =>
              setNewField({ ...newField, section_id: e.target.value ? Number(e.target.value) : null })
            }
            options={sectionOptions}
          />
        </div>
      )}

      {/* اختيار مصدر الحقل - يظهر فقط للاستبيانات الأولية */}
      {isInitialSurvey && targetModelOptions.length > 0 && (
        <div className="space-y-3 p-3 rounded-lg bg-[var(--surface-secondary)] border border-[var(--border-default)]">
          <label className="block text-sm font-medium text-[var(--text-primary)]">{t('surveys.field_source')}</label>
          <div className="flex gap-2">
            <button
              type="button"
              onClick={() => onFieldSourceChange('new')}
              className={`flex-1 flex items-center justify-center gap-2 px-3 py-2 rounded-lg border text-sm transition-colors ${
                fieldSource === 'new'
                  ? 'border-[var(--accent-default)] bg-[var(--accent-subtle)] text-[var(--accent-default)]'
                  : 'border-[var(--border-default)] text-[var(--text-secondary)] hover:bg-[var(--surface-tertiary)]'
              }`}
            >
              <IconPlus className="w-4 h-4" />
              {t('surveys.new_field')}
            </button>
            <button
              type="button"
              onClick={() => onFieldSourceChange('existing')}
              className={`flex-1 flex items-center justify-center gap-2 px-3 py-2 rounded-lg border text-sm transition-colors ${
                fieldSource === 'existing'
                  ? 'border-[var(--accent-default)] bg-[var(--accent-subtle)] text-[var(--accent-default)]'
                  : 'border-[var(--border-default)] text-[var(--text-secondary)] hover:bg-[var(--surface-tertiary)]'
              }`}
            >
              <IconDatabase className="w-4 h-4" />
              {t('surveys.link_to_table')}
            </button>
          </div>

          {/* اختيار الجدول والعمود */}
          {fieldSource === 'existing' && (
            <div className="space-y-3 pt-2">
              <div>
                <label className="block text-xs text-[var(--text-secondary)] mb-1">{t('surveys.target_table')}</label>
                <Select
                  value={selectedTargetModel}
                  onChange={(e) => onTargetModelChange(e.target.value)}
                  options={[{ value: '', label: t('surveys.select_table') }, ...targetModelOptions]}
                />
              </div>

              {selectedTargetModel && (
                <div>
                  <label className="block text-xs text-[var(--text-secondary)] mb-1">{t('surveys.column')}</label>
                  <Select
                    value={selectedColumn}
                    onChange={(e) => onColumnSelect(e.target.value)}
                    options={[{ value: '', label: t('surveys.select_column') }, ...getAvailableColumns()]}
                  />
                </div>
              )}
            </div>
          )}
        </div>
      )}

      <div>
        <label className="block text-sm font-medium text-[var(--text-primary)] mb-1">
          {t('surveys.field_title')} <RequiredIndicator />
        </label>
        <Input
          value={newField.label}
          onChange={(e) => handleLabelChange(e.target.value)}
          placeholder={t('surveys.field_title_placeholder')}
        />
      </div>

      <div>
        <label className="block text-sm font-medium text-[var(--text-primary)] mb-1">
          {t('surveys.field_key_label')} <RequiredIndicator />
          {fieldSource === 'existing' && selectedColumn && (
            <span className="text-xs text-[var(--accent-default)] mr-2">
              <IconLink className="w-3 h-3 inline ml-1" />
              {t('surveys.linked_to')} {selectedTargetModel}.{selectedColumn}
            </span>
          )}
        </label>
        <Input
          value={newField.field_key}
          onChange={(e) => setNewField({ ...newField, field_key: e.target.value, name: e.target.value })}
          placeholder={t('surveys.field_key_placeholder')}
          dir="ltr"
          className="font-mono"
          disabled={fieldSource === 'existing' && !!selectedColumn}
        />
        <p className="text-xs text-[var(--text-secondary)] mt-1">{t('surveys.field_key_hint')}</p>
      </div>

      <div>
        <label className="block text-sm font-medium text-[var(--text-primary)] mb-1">{t('surveys.field_type')}</label>
        <Select
          value={newField.type}
          onChange={(e) => setNewField({ ...newField, type: e.target.value })}
          options={fieldTypes}
        />
      </div>

      {/* Options for select/radio/checkbox */}
      {hasOptions(newField.type) && (
        <div>
          <label className="block text-sm font-medium text-[var(--text-primary)] mb-2">{t('surveys.options')}</label>
          <div className="space-y-2">
            {options.map((option, index) => (
              <div key={index} className="flex items-center gap-2">
                <Input
                  value={option.label}
                  onChange={(e) => updateOption(index, 'label', e.target.value)}
                  placeholder={t('surveys.option_label')}
                  className="flex-1"
                />
                <Input
                  value={option.value}
                  onChange={(e) => updateOption(index, 'value', e.target.value)}
                  placeholder={t('surveys.option_value')}
                  className="flex-1 font-mono"
                  dir="ltr"
                />
                <Button
                  type="button"
                  variant="ghost"
                  size="sm"
                  onClick={() => removeOption(index)}
                  disabled={options.length === 1}
                >
                  <IconTrash className="w-4 h-4" />
                </Button>
              </div>
            ))}
            <Button
              type="button"
              variant="secondary"
              size="sm"
              onClick={addOption}
              leftIcon={<IconPlus className="w-4 h-4" />}
            >
              {t('surveys.add_option')}
            </Button>
          </div>
        </div>
      )}

      <div>
        <Textarea
          value={newField.description}
          onChange={(e) => setNewField({ ...newField, description: e.target.value })}
          rows={2}
          label={t('surveys.helper_description')}
          placeholder={t('surveys.helper_description_placeholder')}
        />
      </div>

      <Checkbox
        checked={newField.is_required}
        onChange={(e) => setNewField({ ...newField, is_required: e.target.checked })}
        label={t('surveys.required_field')}
      />

      {editingFieldId ? (
        <div className="flex gap-2">
          <Button
            onClick={onUpdateField}
            disabled={saving}
            leftIcon={<IconPencil className="w-4 h-4" />}
            className="flex-1"
          >
            {saving ? t('common.saving') : t('common.save_changes')}
          </Button>
          <Button variant="outline" onClick={onCancelEdit} disabled={saving}>
            {t('common.cancel')}
          </Button>
        </div>
      ) : (
        <Button
          onClick={onAddField}
          disabled={saving}
          leftIcon={<IconPlus className="w-4 h-4" />}
          className="w-full"
        >
          {saving ? t('surveys.adding_field') : t('surveys.add_field')}
        </Button>
      )}
    </div>
  );
};

export default FieldForm;
