import { memo } from 'react';
import { useTranslation } from 'react-i18next';
import {IconFileText} from '@tabler/icons-react';
import { Input } from '@shared/ui/Input';
import { RequiredIndicator } from '@shared/ui/RequiredIndicator';
import type { TaskFormData, ValidationErrors } from './types';

interface TaskDetailsSectionProps {
  formData: TaskFormData;
  errors: ValidationErrors;
  onChange: (field: keyof TaskFormData, value: string) => void;
}

const TaskDetailsSection = memo<TaskDetailsSectionProps>(({
  formData,
  errors,
  onChange,
}) => {
  const { t } = useTranslation();
  return (
    <>
      <div className="flex items-center gap-2 mb-4">
        <IconFileText className="h-4 w-4 text-[var(--accent-default)]" />
        <h3 className="text-sm font-semibold text-[var(--text-primary)]">{t('tasks.task_details')}</h3>
      </div>
      <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mb-5">
        <div>
          <label className="block text-xs font-medium text-[var(--text-secondary)] mb-1">
            {t('tasks.task_title')} <RequiredIndicator />
          </label>
          <Input
            value={formData.title}
            onChange={(e) => onChange('title', e.target.value)}
            placeholder={t('tasks.enter_title')}
            error={errors.title?.[0]}
          />
        </div>
        <div>
          <label className="block text-xs font-medium text-[var(--text-secondary)] mb-1">{t('tasks.description')}</label>
          <Input
            value={formData.description}
            onChange={(e) => onChange('description', e.target.value)}
            placeholder={t('tasks.enter_description')}
          />
        </div>
      </div>
    </>
  );
});

TaskDetailsSection.displayName = 'TaskDetailsSection';

export default TaskDetailsSection;
