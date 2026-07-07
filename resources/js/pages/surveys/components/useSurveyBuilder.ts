import { useState, useEffect, useCallback, useRef, useMemo } from 'react';
import { useNavigate } from 'react-router-dom';
import { DragEndEvent } from '@dnd-kit/core';
import { arrayMove } from '@dnd-kit/sortable';
import { surveysApi } from '@entities/survey';
import { useToast } from '@shared/ui/Toast';
import {
  Survey,
  SurveyField,
  SurveySection,
  TargetModel,
  FieldOption,
  initialFieldData,
  hasOptions,
  columnTypeToFieldType,
} from './types';

interface UseSurveyBuilderProps {
  id: string | undefined;
}

export const useSurveyBuilder = ({ id }: UseSurveyBuilderProps) => {
  const navigate = useNavigate();
  const { showToast } = useToast();

  // استخدام ref لتجنب إعادة إنشاء الدوال
  const showToastRef = useRef(showToast);
  showToastRef.current = showToast;

  // حالات البيانات الأساسية
  const [survey, setSurvey] = useState<Survey | null>(null);
  const [fields, setFields] = useState<SurveyField[]>([]);
  const [sections, setSections] = useState<SurveySection[]>([]);
  const [targetModels, setTargetModels] = useState<Record<string, TargetModel>>({});

  // حالات التحميل والحفظ
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);

  // حالات نموذج الحقل
  const [newField, setNewField] = useState(initialFieldData);
  const [options, setOptions] = useState<FieldOption[]>([{ label: '', value: '' }]);
  const [editingFieldId, setEditingFieldId] = useState<number | null>(null);

  // حالات الأقسام
  const [showSectionForm, setShowSectionForm] = useState(false);
  const [editingSectionId, setEditingSectionId] = useState<number | null>(null);
  const [sectionTitle, setSectionTitle] = useState('');
  const [sectionDescription, setSectionDescription] = useState('');

  // حالات الربط بالجداول
  const [fieldSource, setFieldSource] = useState<'new' | 'existing'>('new');
  const [selectedTargetModel, setSelectedTargetModel] = useState<string>('');
  const [selectedColumn, setSelectedColumn] = useState<string>('');

  // التحقق من صحة id
  const isValidId = useMemo(() => Boolean(id && !isNaN(Number(id))), [id]);

  // جلب البيانات
  const fetchSurvey = useCallback(async () => {
    if (!isValidId) {
      setLoading(false);
      return;
    }

    try {
      const response = await surveysApi.getById(Number(id));
      const surveyData = response as Survey;
      setSurvey(surveyData);
      setFields(surveyData.fields || []);
    } catch (error) {
      console.error('Failed to fetch survey:', error);
      showToastRef.current('error', 'فشل في تحميل الاستبيان');
    } finally {
      setLoading(false);
    }
  }, [id, isValidId]);

  const fetchSections = useCallback(async () => {
    if (!isValidId) return;

    try {
      const response = await surveysApi.getSections(Number(id));
      const data = (response as any)?.data || response;
      setSections(Array.isArray(data) ? data : []);
    } catch (error) {
      console.error('Failed to fetch sections:', error);
    }
  }, [id, isValidId]);

  const fetchTargetModels = useCallback(async () => {
    try {
      const response = await surveysApi.getAvailableTargets();
      const data = (response as any)?.data || response;
      setTargetModels(data);
    } catch (error) {
      console.error('Failed to fetch target models:', error);
    }
  }, []);

  useEffect(() => {
    fetchSurvey();
    fetchSections();
    fetchTargetModels();
  }, [fetchSurvey, fetchSections, fetchTargetModels]);

  // معالجات الحقول
  const handleColumnSelect = (columnKey: string) => {
    setSelectedColumn(columnKey);
    if (!columnKey || !selectedTargetModel) return;

    const targetModel = targetModels[selectedTargetModel];
    if (!targetModel) return;

    const column = targetModel.columns[columnKey];
    if (!column) return;

    const fieldType = columnTypeToFieldType[column.type] || 'text';
    setNewField({
      field_key: columnKey,
      name: columnKey,
      label: column.label,
      description: '',
      type: fieldType,
      config: {
        mapped_to: {
          table: selectedTargetModel,
          column: columnKey,
        },
      },
      is_required: column.required || false,
      section_id: newField.section_id,
    });
  };

  const handleTargetModelChange = (model: string) => {
    setSelectedTargetModel(model);
    setSelectedColumn('');
    setNewField({ ...initialFieldData, section_id: newField.section_id });
  };

  const handleFieldSourceChange = (source: 'new' | 'existing') => {
    setFieldSource(source);
    setSelectedTargetModel('');
    setSelectedColumn('');
    setNewField({ ...initialFieldData, section_id: newField.section_id });
  };

  const handleAddField = async () => {
    if (!newField.label || !newField.field_key) {
      showToastRef.current('error', 'يرجى إدخال عنوان الحقل والاسم البرمجي');
      return;
    }

    if (fields.some((f) => f.field_key === newField.field_key)) {
      showToastRef.current('error', 'الاسم البرمجي موجود مسبقاً');
      return;
    }

    setSaving(true);
    try {
      const fieldData = {
        ...newField,
        name: newField.name || newField.field_key,
        config: hasOptions(newField.type)
          ? { ...newField.config, options: options.filter((o) => o.label && o.value) }
          : newField.config,
      };

      await surveysApi.addField(Number(id), fieldData);
      showToastRef.current('success', 'تم إضافة الحقل بنجاح');
      setNewField({ ...initialFieldData, section_id: newField.section_id });
      setOptions([{ label: '', value: '' }]);
      setSelectedColumn('');
      fetchSurvey();
    } catch (error: any) {
      const message = error.response?.data?.message || error.message || 'فشل في إضافة الحقل';
      showToastRef.current('error', message);
    } finally {
      setSaving(false);
    }
  };

  const handleDeleteField = async (fieldId: number) => {
    if (!confirm('هل أنت متأكد من حذف هذا الحقل؟')) return;

    try {
      await surveysApi.deleteField(Number(id), fieldId);
      showToastRef.current('success', 'تم حذف الحقل');
      fetchSurvey();
    } catch (error) {
      showToastRef.current('error', 'فشل في حذف الحقل');
    }
  };

  const handleEditField = (field: SurveyField) => {
    setEditingFieldId(field.id || null);
    setNewField({
      field_key: field.field_key,
      name: field.name,
      label: field.label,
      description: field.description,
      type: field.type,
      config: field.config || {},
      is_required: field.is_required,
      section_id: field.section_id,
    });

    if (hasOptions(field.type) && field.config?.options) {
      setOptions(field.config.options);
    } else {
      setOptions([{ label: '', value: '' }]);
    }

    if (field.config?.mapped_to) {
      setFieldSource('existing');
      setSelectedTargetModel(field.config.mapped_to.table);
      setSelectedColumn(field.config.mapped_to.column);
    } else {
      setFieldSource('new');
      setSelectedTargetModel('');
      setSelectedColumn('');
    }
  };

  const handleCancelEdit = () => {
    setEditingFieldId(null);
    setNewField(initialFieldData);
    setOptions([{ label: '', value: '' }]);
    setFieldSource('new');
    setSelectedTargetModel('');
    setSelectedColumn('');
  };

  const handleUpdateField = async () => {
    if (!editingFieldId) return;

    if (!newField.label || !newField.field_key) {
      showToastRef.current('error', 'يرجى إدخال عنوان الحقل والاسم البرمجي');
      return;
    }

    if (fields.some((f) => f.field_key === newField.field_key && f.id !== editingFieldId)) {
      showToastRef.current('error', 'الاسم البرمجي موجود مسبقاً');
      return;
    }

    setSaving(true);
    try {
      const fieldData = {
        ...newField,
        name: newField.name || newField.field_key,
        config: hasOptions(newField.type)
          ? { ...newField.config, options: options.filter((o) => o.label && o.value) }
          : newField.config,
      };

      await surveysApi.updateField(Number(id), editingFieldId, fieldData);
      showToastRef.current('success', 'تم تحديث الحقل بنجاح');
      handleCancelEdit();
      fetchSurvey();
    } catch (error: any) {
      const message = error.response?.data?.message || error.message || 'فشل في تحديث الحقل';
      showToastRef.current('error', message);
    } finally {
      setSaving(false);
    }
  };

  // معالجات السحب والإفلات
  const handleFieldDragEnd = async (event: DragEndEvent) => {
    const { active, over } = event;

    if (!over) return;

    const activeFieldId = active.id as number;
    const activeField = fields.find((f) => f.id === activeFieldId);
    if (!activeField) return;

    const overId = String(over.id);

    // التحقق إذا تم الإفلات على قسم فارغ
    if (overId.startsWith('section-')) {
      const targetSectionId = overId === 'section-null' ? null : Number(overId.replace('section-', ''));

      if (activeField.section_id === targetSectionId) return;

      const updatedFields = fields.map((f) =>
        f.id === activeFieldId ? { ...f, section_id: targetSectionId } : f
      );
      setFields(updatedFields);

      try {
        await surveysApi.updateField(Number(id), activeFieldId, { section_id: targetSectionId });
        showToastRef.current('success', 'تم نقل الحقل للقسم');
      } catch (error) {
        showToastRef.current('error', 'فشل في نقل الحقل');
        fetchSurvey();
      }
      return;
    }

    // الإفلات على حقل آخر
    const overFieldId = over.id as number;
    const overField = fields.find((f) => f.id === overFieldId);
    if (!overField) return;

    if (activeField.section_id !== overField.section_id) {
      const targetSectionId = overField.section_id;

      const updatedFields = fields.map((f) =>
        f.id === activeFieldId ? { ...f, section_id: targetSectionId } : f
      );
      setFields(updatedFields);

      try {
        await surveysApi.updateField(Number(id), activeFieldId, { section_id: targetSectionId });
        showToastRef.current('success', 'تم نقل الحقل للقسم');
      } catch (error) {
        showToastRef.current('error', 'فشل في نقل الحقل');
        fetchSurvey();
      }
      return;
    }

    // إعادة ترتيب داخل نفس القسم
    if (active.id !== over.id) {
      const oldIndex = fields.findIndex((f) => f.id === active.id);
      const newIndex = fields.findIndex((f) => f.id === over.id);

      if (oldIndex === -1 || newIndex === -1) return;

      const reorderedFields = arrayMove(fields, oldIndex, newIndex).map((f, index) => ({
        ...f,
        order: index + 1,
      }));
      setFields(reorderedFields);

      try {
        const fieldIds = reorderedFields.map((f) => f.id!);
        await surveysApi.reorderFields(Number(id), fieldIds);
      } catch (error) {
        showToastRef.current('error', 'فشل في حفظ الترتيب');
        fetchSurvey();
      }
    }
  };

  // معالجات الأقسام
  const handleAddSection = async () => {
    if (!sectionTitle.trim()) {
      showToastRef.current('error', 'يرجى إدخال عنوان القسم');
      return;
    }

    setSaving(true);
    try {
      await surveysApi.addSection(Number(id), {
        title: sectionTitle,
        description: sectionDescription || undefined,
      });
      showToastRef.current('success', 'تم إضافة القسم بنجاح');
      setSectionTitle('');
      setSectionDescription('');
      setShowSectionForm(false);
      fetchSections();
    } catch (error: any) {
      const message = error.response?.data?.message || 'فشل في إضافة القسم';
      showToastRef.current('error', message);
    } finally {
      setSaving(false);
    }
  };

  const handleEditSection = (section: SurveySection) => {
    setEditingSectionId(section.id);
    setSectionTitle(section.title);
    setSectionDescription(section.description || '');
    setShowSectionForm(true);
  };

  const handleUpdateSection = async () => {
    if (!editingSectionId || !sectionTitle.trim()) {
      showToastRef.current('error', 'يرجى إدخال عنوان القسم');
      return;
    }

    setSaving(true);
    try {
      await surveysApi.updateSection(Number(id), editingSectionId, {
        title: sectionTitle,
        description: sectionDescription || undefined,
      });
      showToastRef.current('success', 'تم تحديث القسم بنجاح');
      handleCancelSectionEdit();
      fetchSections();
    } catch (error: any) {
      const message = error.response?.data?.message || 'فشل في تحديث القسم';
      showToastRef.current('error', message);
    } finally {
      setSaving(false);
    }
  };

  const handleDeleteSection = async (sectionId: number) => {
    const sectionFields = fields.filter((f) => f.section_id === sectionId);
    const confirmMessage =
      sectionFields.length > 0
        ? `هل أنت متأكد من حذف هذا القسم؟ سيتم نقل ${sectionFields.length} حقل إلى "بدون قسم".`
        : 'هل أنت متأكد من حذف هذا القسم؟';

    if (!confirm(confirmMessage)) return;

    try {
      await surveysApi.deleteSection(Number(id), sectionId);
      showToastRef.current('success', 'تم حذف القسم');
      fetchSections();
      fetchSurvey();
    } catch (error) {
      showToastRef.current('error', 'فشل في حذف القسم');
    }
  };

  const handleCancelSectionEdit = () => {
    setEditingSectionId(null);
    setSectionTitle('');
    setSectionDescription('');
    setShowSectionForm(false);
  };

  // نشر الاستبيان
  const handlePublish = async () => {
    if (fields.length === 0) {
      showToastRef.current('error', 'يجب إضافة حقل واحد على الأقل قبل النشر');
      return;
    }

    try {
      await surveysApi.publish(Number(id));
      showToastRef.current('success', 'تم نشر الاستبيان بنجاح');
      navigate(`/surveys/${id}`);
    } catch (error) {
      showToastRef.current('error', 'فشل في نشر الاستبيان');
    }
  };

  return {
    // البيانات
    survey,
    fields,
    sections,
    targetModels,
    loading,
    saving,

    // نموذج الحقل
    newField,
    setNewField,
    options,
    setOptions,
    editingFieldId,

    // الأقسام
    showSectionForm,
    setShowSectionForm,
    editingSectionId,
    sectionTitle,
    setSectionTitle,
    sectionDescription,
    setSectionDescription,

    // الربط بالجداول
    fieldSource,
    selectedTargetModel,
    selectedColumn,

    // معالجات الحقول
    handleColumnSelect,
    handleTargetModelChange,
    handleFieldSourceChange,
    handleAddField,
    handleDeleteField,
    handleEditField,
    handleCancelEdit,
    handleUpdateField,
    handleFieldDragEnd,

    // معالجات الأقسام
    handleAddSection,
    handleEditSection,
    handleUpdateSection,
    handleDeleteSection,
    handleCancelSectionEdit,

    // النشر
    handlePublish,
  };
};

export default useSurveyBuilder;
