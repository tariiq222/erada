import { memo } from 'react';
import { useTranslation } from 'react-i18next';
import { Card, CardHeader, CardTitle, CardContent } from '@shared/ui/Card';
import { Button } from '@shared/ui/Button';
import { IconButton } from '@shared/ui/IconButton';
import { Input } from '@shared/ui/Input';
import {IconTarget, IconPlus, IconTrash} from '@tabler/icons-react';

interface ObjectivesSectionProps {
  objectives: string[];
  onObjectiveChange: (index: number, value: string) => void;
  onAddObjective: () => void;
  onRemoveObjective: (index: number) => void;
  compact?: boolean;
}

const ObjectivesSection = memo<ObjectivesSectionProps>(({
  objectives,
  onObjectiveChange,
  onAddObjective,
  onRemoveObjective,
  compact = false,
}) => {
  const { t } = useTranslation();

  const content = (
    <>
      <div className={compact ? 'grid grid-cols-1 sm:grid-cols-2 gap-2' : 'grid grid-cols-1 md:grid-cols-2 gap-3'}>
        {objectives.map((objective, index) => (
          <div key={index} className="flex items-center gap-2">
            <span className={`${compact ? 'h-5 w-5' : 'h-6 w-6'} rounded-full bg-[var(--accent-subtle)] text-[var(--accent-default)] flex items-center justify-center text-xs font-medium shrink-0`}>
              {index + 1}
            </span>
            <Input
              value={objective}
              onChange={(e) => onObjectiveChange(index, e.target.value)}
              placeholder={t('projects.enter_objective')}
              className="flex-1 text-sm"
            />
            {objectives.length > 1 && (
              <IconButton
                type="button"
                variant="dangerStrong"
                size="xs"
                onClick={() => onRemoveObjective(index)}
                aria-label={t('common.delete')}
              >
                <IconTrash className="h-3.5 w-3.5" />
              </IconButton>
            )}
          </div>
        ))}
      </div>
      <Button
        type="button"
        variant="outline"
        size="sm"
        onClick={onAddObjective}
        leftIcon={<IconPlus className="h-4 w-4" />}
        className={compact ? 'mt-0' : 'mt-3'}
      >
        {t('projects.add_objective')}
      </Button>
    </>
  );

  if (compact) {
    return <div className="space-y-3">{content}</div>;
  }

  return (
    <Card className="p-0">
      <CardHeader className="p-4 pb-0 sm:p-6 sm:pb-0">
        <CardTitle className="flex items-center gap-2">
          <IconTarget className="h-5 w-5 text-[var(--accent-default)]" />
          {t('projects.objectives')}
        </CardTitle>
      </CardHeader>
      <CardContent className="p-4 sm:p-6">
        {content}
      </CardContent>
    </Card>
  );
});

ObjectivesSection.displayName = 'ObjectivesSection';

export default ObjectivesSection;
