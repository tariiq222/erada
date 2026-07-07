import { memo } from 'react';
import { useTranslation } from 'react-i18next';
import { Card, CardHeader, CardTitle, CardContent } from '@shared/ui/Card';
import { Button } from '@shared/ui/Button';
import { IconButton } from '@shared/ui/IconButton';
import { Input } from '@shared/ui/Input';
import { DatePicker } from '@shared/ui/DatePicker';
import { DurationChips, TASK_DURATIONS } from '@shared/ui/DurationChips';
import {IconFlag, IconPlus, IconTrash, IconBulb} from '@tabler/icons-react';
import type { MilestoneItem, DeliverableItem } from './types';

interface MilestonesSectionProps {
  milestones: MilestoneItem[];
  projectStartDate: string;
  projectEndDate: string;
  onMilestoneChange: (index: number, field: keyof Omit<MilestoneItem, 'deliverables'>, value: string) => void;
  onAddMilestone: () => void;
  onRemoveMilestone: (index: number) => void;
  onSuggestMilestones?: (names: string[]) => void;
  onDeliverableChange: (milestoneIndex: number, deliverableIndex: number, field: keyof DeliverableItem, value: string) => void;
  onAddDeliverable: (milestoneIndex: number) => void;
  onRemoveDeliverable: (milestoneIndex: number, deliverableIndex: number) => void;
  compact?: boolean;
}

const MilestonesSection = memo<MilestonesSectionProps>(({
  milestones,
  projectStartDate,
  projectEndDate,
  onMilestoneChange,
  onAddMilestone,
  onRemoveMilestone,
  onSuggestMilestones,
  onDeliverableChange,
  onAddDeliverable,
  onRemoveDeliverable,
  compact = false,
}) => {
  const { t } = useTranslation();
  const hasDateRange = projectStartDate && projectEndDate;
  // Offer the one-tap template only at the start, before the user has named any
  // milestone, so it never overwrites real work.
  const canSuggest = !!onSuggestMilestones && hasDateRange && milestones.every((m) => !m.name.trim());

  const content = (
    <>
      {hasDateRange ? (
        <div className={`${compact ? 'mb-3 p-2' : 'mb-4 p-3'} border border-[var(--border-default)] bg-[var(--surface-base)] rounded-lg`}>
          <p className="text-xs text-[var(--text-secondary)]">
            <span className="font-medium">{t('projects.milestone_date_range')}:</span>{' '}
            {t('projects.from')} <span className="font-medium">{projectStartDate}</span> {t('projects.to')} <span className="font-medium">{projectEndDate}</span>
          </p>
        </div>
      ) : (
        <div className={`${compact ? 'mb-3 p-2' : 'mb-4 p-3'} flex items-center gap-2 border border-[var(--border-default)] bg-[var(--surface-base)] rounded-lg`}>
          <span className="h-2 w-2 shrink-0 rounded-full bg-[var(--status-warning)]" />
          <p className="text-xs text-[var(--text-secondary)]">
            {t('projects.set_dates_first_milestones')}
          </p>
        </div>
      )}
      <div className="grid grid-cols-1 gap-3">
        {milestones.map((milestone, index) => (
          <div key={index} className={`${compact ? 'p-3' : 'p-4'} border border-[var(--border-default)] rounded-lg bg-[var(--surface-muted)]/50`}>
            <div className="flex items-center justify-between mb-3">
              <div className="flex items-center gap-2">
                <span className={`${compact ? 'h-5 w-5' : 'h-6 w-6'} rounded-full bg-[var(--accent-default)] text-[var(--text-inverse)] flex items-center justify-center text-xs font-medium`}>
                  {index + 1}
                </span>
              </div>
              {milestones.length > 1 && (
                <IconButton
                  type="button"
                  variant="dangerStrong"
                  size="xs"
                  onClick={() => onRemoveMilestone(index)}
                  aria-label={t('projects.remove_milestone', { defaultValue: t('common.delete') })}
                  className="h-11 w-11 shrink-0 lg:h-7 lg:w-7"
                >
                  <IconTrash className="h-3.5 w-3.5" />
                </IconButton>
              )}
            </div>
            <div className="space-y-3">
              <div>
                <label className="block text-xs font-medium text-[var(--text-tertiary)] mb-1">{t('projects.milestone_name')}</label>
                <Input
                  value={milestone.name}
                  onChange={(e) => onMilestoneChange(index, 'name', e.target.value)}
                  placeholder={t('projects.milestone_name_placeholder')}
                  className="min-h-11 text-sm lg:min-h-9"
                />
              </div>
              <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div>
                  <label className="block text-xs font-medium text-[var(--text-tertiary)] mb-1">{t('projects.start_date')}</label>
                  <DatePicker
                    value={milestone.start_date}
                    onChange={(value) => onMilestoneChange(index, 'start_date', value)}
                    minDate={projectStartDate || undefined}
                    maxDate={milestone.due_date || projectEndDate || undefined}
                    placeholder={t('projects.start_date')}
                    disabled={!hasDateRange}
                    className="min-h-11 lg:min-h-9"
                  />
                </div>
                <div>
                  <label className="block text-xs font-medium text-[var(--text-tertiary)] mb-1">{t('projects.end_date')}</label>
                  <DatePicker
                    value={milestone.due_date}
                    onChange={(value) => onMilestoneChange(index, 'due_date', value)}
                    minDate={milestone.start_date || projectStartDate || undefined}
                    maxDate={projectEndDate || undefined}
                    placeholder={t('projects.end_date')}
                    disabled={!hasDateRange}
                    className="min-h-11 lg:min-h-9"
                  />
                </div>
              </div>
              <DurationChips
                startDate={milestone.start_date}
                endDate={milestone.due_date}
                onApply={(start, end) => {
                  onMilestoneChange(index, 'start_date', start);
                  onMilestoneChange(index, 'due_date', end);
                }}
                options={TASK_DURATIONS}
                fallbackStart={projectStartDate || undefined}
                disabled={!hasDateRange}
              />
              <div>
                <label className="block text-xs font-medium text-[var(--text-tertiary)] mb-1">{t('projects.description_optional')}</label>
                <Input
                  value={milestone.description}
                  onChange={(e) => onMilestoneChange(index, 'description', e.target.value)}
                  placeholder={t('projects.milestone_description_placeholder')}
                  className="min-h-11 text-sm lg:min-h-9"
                />
              </div>

              <div className="mt-3 pt-3 border-t border-[var(--border-default)]">
                <div className="flex items-center justify-between mb-2">
                  <label className="block text-xs font-semibold text-[var(--text-secondary)]">{t('projects.deliverables')}</label>
                </div>
                <div className="space-y-2">
                  {milestone.deliverables.map((deliverable, dIndex) => (
                    <div key={dIndex} className="flex items-center gap-2">
                      <span className="text-xs text-[var(--text-tertiary)] shrink-0">{dIndex + 1}.</span>
                      <Input
                        value={deliverable.name}
                        onChange={(e) => onDeliverableChange(index, dIndex, 'name', e.target.value)}
                        placeholder={t('projects.deliverable_name')}
                        className="min-h-11 flex-1 text-xs lg:min-h-9"
                      />
                      {milestone.deliverables.length > 1 && (
                        <IconButton
                          type="button"
                          variant="dangerStrong"
                          size="2xs"
                          onClick={() => onRemoveDeliverable(index, dIndex)}
                          aria-label={t('common.delete')}
                          className="h-11 w-11 shrink-0 lg:h-6 lg:w-6"
                        >
                          <IconTrash className="h-3 w-3" />
                        </IconButton>
                      )}
                    </div>
                  ))}
                  <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    onClick={() => onAddDeliverable(index)}
                    className="h-11 text-[var(--accent-default)] hover:text-[var(--accent-hover)] text-xs lg:h-8"
                    leftIcon={<IconPlus className="h-3 w-3" />}
                  >
                    {t('projects.add_deliverable')}
                  </Button>
                </div>
              </div>
            </div>
          </div>
        ))}
      </div>
      <div className={`flex flex-wrap items-center gap-2 ${compact ? 'mt-3' : 'mt-4'}`}>
        <Button
          type="button"
          variant="outline"
          size="sm"
          onClick={onAddMilestone}
          leftIcon={<IconPlus className="h-4 w-4" />}
          disabled={!hasDateRange}
          className="h-11 lg:h-8"
        >
          {t('projects.add_milestone')}
        </Button>
        {canSuggest && (
          <Button
            type="button"
            variant="ghost"
            size="sm"
            onClick={() => onSuggestMilestones?.([
              t('projects.tpl_planning'),
              t('projects.tpl_execution'),
              t('projects.tpl_closure'),
            ])}
            leftIcon={<IconBulb className="h-4 w-4" />}
            className="h-11 text-[var(--accent-default)] hover:text-[var(--accent-hover)] lg:h-8"
          >
            {t('projects.suggest_milestones')}
          </Button>
        )}
      </div>
    </>
  );

  if (compact) {
    return <div>{content}</div>;
  }

  return (
    <Card className="p-0">
      <CardHeader className="p-4 pb-0 sm:p-6 sm:pb-0">
        <CardTitle className="flex items-center gap-2">
          <IconFlag className="h-5 w-5 text-[var(--accent-default)]" />
          {t('projects.milestones')}
        </CardTitle>
      </CardHeader>
      <CardContent className="p-4 sm:p-6">
        {content}
      </CardContent>
    </Card>
  );
});

MilestonesSection.displayName = 'MilestonesSection';

export default MilestonesSection;
