import React from 'react';
import { useParams, Link } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { Button, Card, Breadcrumb, Skeleton, PageHeader } from '@shared/ui';
import {IconEye, IconSend, IconSettings, IconX, IconInfoCircle} from '@tabler/icons-react';
import { FieldForm, FieldsList, useSurveyBuilder } from './components';

const SurveyBuilder: React.FC = () => {
  const { t } = useTranslation();
  const { id } = useParams<{ id: string }>();

  const {
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
  } = useSurveyBuilder({ id });

  if (loading) {
    return (
      <div className="space-y-4 sm:space-y-6">
        <Skeleton className="h-6 w-64" />
        <div className="flex justify-between items-center">
          <div className="flex items-center gap-3">
            <Skeleton className="h-10 w-10 rounded-lg" />
            <div className="space-y-2">
              <Skeleton className="h-5 w-48" />
              <Skeleton className="h-4 w-32" />
            </div>
          </div>
          <div className="flex gap-2">
            <Skeleton className="h-9 w-24 rounded-lg" />
            <Skeleton className="h-9 w-20 rounded-lg" />
          </div>
        </div>
        <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
          <Card className="p-6 border border-[var(--border-default)]">
            <Skeleton className="h-6 w-32 mb-4" />
            <div className="space-y-4">
              <Skeleton className="h-10 w-full rounded-lg" />
              <Skeleton className="h-10 w-full rounded-lg" />
              <Skeleton className="h-10 w-full rounded-lg" />
              <Skeleton className="h-10 w-full rounded-lg" />
            </div>
          </Card>
          <div className="lg:col-span-2">
            <Card className="p-6 border border-[var(--border-default)]">
              <Skeleton className="h-6 w-40 mb-4" />
              <div className="space-y-3">
                {[1, 2, 3].map((i) => (
                  <div key={i} className="flex items-center gap-4 p-4 rounded-lg border border-[var(--border-default)]">
                    <Skeleton className="h-8 w-8 rounded-full" />
                    <div className="flex-1 space-y-2">
                      <Skeleton className="h-4 w-1/3" />
                      <Skeleton className="h-3 w-1/4" />
                    </div>
                  </div>
                ))}
              </div>
            </Card>
          </div>
        </div>
      </div>
    );
  }

  if (!survey) {
    return (
      <div className="flex flex-col items-center justify-center min-h-[400px] gap-4">
        <p className="text-[var(--text-secondary)]">{t('surveys.not_found')}</p>
        <Link to="/surveys">
          <Button variant="secondary">{t('common.back_to_list')}</Button>
        </Link>
      </div>
    );
  }

  if (survey.status !== 'draft') {
    return (
      <div className="flex flex-col items-center justify-center min-h-[400px] gap-4">
        <p className="text-[var(--text-secondary)]">{t('surveys.cannot_edit_published')}</p>
        <Link to={`/surveys/${id}`}>
          <Button variant="secondary">{t('surveys.back_to_survey')}</Button>
        </Link>
      </div>
    );
  }

  const isInitialSurvey = survey.type === 'initial';

  return (
    <div className="space-y-4 sm:space-y-6">
      {/* Breadcrumb */}
      <Breadcrumb
        items={[
          { label: t('surveys.title'), href: '/surveys' },
          { label: survey.title, href: `/surveys/${id}` },
          { label: t('surveys.build_fields') },
        ]}
      />

      <PageHeader
        title={`${t('surveys.build')}: ${survey.title}`}
        subtitle={`${t('surveys.build_subtitle')} • ${fields.length} ${t('surveys.field')} • ${sections.length} ${t('surveys.section')}`}
        icon={IconSettings}
        iconTone="survey"
        actions={
          <>
            <Link to={`/surveys/${id}`}>
              <Button variant="outline" size="sm" leftIcon={<IconEye className="h-4 w-4" />}>
                {t('surveys.preview')}
              </Button>
            </Link>
            <Button size="sm" leftIcon={<IconSend className="h-4 w-4" />} onClick={handlePublish}>
              {t('surveys.publish')}
            </Button>
          </>
        }
      />

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {/* Left Column - Field Form */}
        <div className="lg:col-span-1">
          <Card className="p-6 sticky top-4 border border-[var(--border-default)]">
            <div className="flex items-center justify-between mb-4">
              <h2 className="text-lg font-semibold text-[var(--text-primary)]">
                {editingFieldId ? t('surveys.edit_field') : t('surveys.add_new_field')}
              </h2>
              {editingFieldId && (
                <button
                  onClick={handleCancelEdit}
                  className="p-1 rounded-lg text-[var(--text-tertiary)] hover:bg-[var(--surface-tertiary)] hover:text-[var(--text-primary)] transition-colors"
                  title={t('common.cancel_edit')}
                  aria-label={t('common.cancel_edit')}
                >
                  <IconX className="w-4 h-4" />
                </button>
              )}
            </div>

            <FieldForm
              newField={newField}
              setNewField={setNewField}
              options={options}
              setOptions={setOptions}
              editingFieldId={editingFieldId}
              sections={sections}
              targetModels={targetModels}
              fieldSource={fieldSource}
              selectedTargetModel={selectedTargetModel}
              selectedColumn={selectedColumn}
              isInitialSurvey={isInitialSurvey}
              saving={saving}
              fields={fields}
              onFieldSourceChange={handleFieldSourceChange}
              onTargetModelChange={handleTargetModelChange}
              onColumnSelect={handleColumnSelect}
              onAddField={handleAddField}
              onUpdateField={handleUpdateField}
              onCancelEdit={handleCancelEdit}
            />
          </Card>
        </div>

        {/* Right Column - Fields List */}
        <div className="lg:col-span-2">
          <FieldsList
            fields={fields}
            sections={sections}
            editingFieldId={editingFieldId}
            editingSectionId={editingSectionId}
            sectionTitle={sectionTitle}
            showSectionForm={showSectionForm}
            saving={saving}
            isInitialSurvey={isInitialSurvey}
            onEditField={handleEditField}
            onDeleteField={handleDeleteField}
            onFieldDragEnd={handleFieldDragEnd}
            onEditSection={handleEditSection}
            onUpdateSection={handleUpdateSection}
            onCancelSectionEdit={handleCancelSectionEdit}
            onDeleteSection={handleDeleteSection}
            onSectionTitleChange={setSectionTitle}
            onAddSection={handleAddSection}
            onShowSectionForm={() => setShowSectionForm(true)}
          />

          {/* Tips */}
          <Card className="p-4 mt-4 border border-[var(--border-default)] bg-[var(--accent-subtle)]">
            <div className="flex items-start gap-3">
              <div className="h-8 w-8 rounded-lg bg-[var(--accent-default)] flex items-center justify-center shrink-0">
                <IconInfoCircle className="w-4 h-4 text-[var(--text-inverse)]" />
              </div>
              <div className="text-sm">
                <p className="font-medium mb-2 text-[var(--text-primary)]">{t('surveys.tips')}:</p>
                <ul className="list-disc list-inside space-y-1 text-[var(--text-secondary)]">
                  <li>{t('surveys.tip_sections')}</li>
                  <li>{t('surveys.tip_field_key')}</li>
                  {isInitialSurvey && <li>{t('surveys.tip_link_table')}</li>}
                  <li>{t('surveys.tip_required')}</li>
                  <li>{t('surveys.tip_no_edit_after_publish')}</li>
                </ul>
              </div>
            </div>
          </Card>
        </div>
      </div>
    </div>
  );
};

export default SurveyBuilder;
