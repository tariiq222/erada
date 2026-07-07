import React from 'react';
import { useTranslation } from 'react-i18next';
import {IconListCheck, IconPlus} from '@tabler/icons-react';
import { Card, Button, EmptyState } from '@shared/ui';

export interface TasksEmptyStateProps {
  onCreateTask: () => void;
}

const TasksEmptyState: React.FC<TasksEmptyStateProps> = ({ onCreateTask }) => {
  const { t } = useTranslation();
  return (
    <Card>
      <EmptyState
        icon={IconListCheck}
        title={t('projects.no_tasks')}
        description={t('projects.start_adding_task')}
        size="lg"
        action={
          <Button leftIcon={<IconPlus className="h-4 w-4" />} onClick={onCreateTask}>
            {t('projects.add_task')}
          </Button>
        }
      />
    </Card>
  );
};

export default TasksEmptyState;
