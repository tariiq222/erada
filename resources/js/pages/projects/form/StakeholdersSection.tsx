import { memo } from 'react';
import { useTranslation } from 'react-i18next';
import { Card, CardHeader, CardTitle, CardContent } from '@shared/ui/Card';
import { Button } from '@shared/ui/Button';
import { IconButton } from '@shared/ui/IconButton';
import { Select } from '@shared/ui/Select';
import {IconUsers, IconUser, IconPlus, IconTrash, IconInfoCircle} from '@tabler/icons-react';
import { influenceOptions, stakeholderRoleOptions } from './constants';
import type { StakeholderItem, UserOption } from './types';

interface StakeholdersSectionProps {
  stakeholders: StakeholderItem[];
  users: UserOption[];
  onStakeholderChange: (index: number, field: keyof StakeholderItem, value: string) => void;
  onAddStakeholder: () => void;
  onRemoveStakeholder: (index: number) => void;
  onSelectStakeholder: (index: number, userId: number) => void;
  compact?: boolean;
}

const StakeholdersSection = memo<StakeholdersSectionProps>(({
  stakeholders,
  users,
  onStakeholderChange,
  onAddStakeholder,
  onRemoveStakeholder,
  onSelectStakeholder,
  compact = false,
}) => {
  const { t } = useTranslation();

  const translatedInfluenceOptions = influenceOptions.map(opt => ({ value: opt.value, label: t(opt.labelKey) }));
  const translatedStakeholderRoleOptions = stakeholderRoleOptions.map(opt => ({ value: opt.value, label: t(opt.labelKey) }));
  const userOptions = users.map((u) => ({ value: String(u.id), label: u.name }));

  const content = (
    <>
      {compact && (
        <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
          <p className="text-xs text-[var(--text-secondary)] flex items-start gap-1">
            <IconInfoCircle className="h-3.5 w-3.5 mt-0 shrink-0" />
            <span>{t('projects.stakeholders_description')}</span>
          </p>
          <Button
            type="button"
            variant="outline"
            size="sm"
            onClick={onAddStakeholder}
            leftIcon={<IconPlus className="h-3.5 w-3.5" />}
            className="h-11 w-full shrink-0 sm:w-auto lg:h-8"
          >
            {t('projects.add_stakeholder')}
          </Button>
        </div>
      )}

      <div className={compact ? 'grid grid-cols-1 sm:grid-cols-2 gap-2' : 'grid grid-cols-1 lg:grid-cols-2 gap-4'}>
        {stakeholders.map((stakeholder, index) => {
          return (
            <div key={index} className={`${compact ? 'p-2' : 'p-3'} border border-[var(--border-default)] rounded-lg bg-[var(--surface-muted)]/50`}>
              <div className="flex items-center justify-between mb-2">
                <span className={`${compact ? 'text-xs' : 'text-sm'} font-medium text-[var(--text-primary)] flex items-center gap-1`}>
                  <IconUser className="h-3.5 w-3.5 text-[var(--accent-default)]" />
                  {t('projects.stakeholder_number', { number: index + 1 })}
                </span>
                {stakeholders.length > 1 && (
                  <IconButton
                    type="button"
                    variant="dangerStrong"
                    size="2xs"
                    onClick={() => onRemoveStakeholder(index)}
                    aria-label={t('common.delete')}
                    className="h-11 w-11 shrink-0 lg:h-6 lg:w-6"
                  >
                    <IconTrash className="h-3 w-3" />
                  </IconButton>
                )}
              </div>
              <div className="space-y-2">
                <Select
                  options={userOptions}
                  value={stakeholder.user_id ? String(stakeholder.user_id) : ''}
                  onChange={(e) => onSelectStakeholder(index, Number(e.target.value))}
                  placeholder={t('projects.select_stakeholder')}
                  searchable
                />
                <div className="grid grid-cols-1 gap-2">
                  <Select
                    value={stakeholder.role}
                    onChange={(e) => onStakeholderChange(index, 'role', e.target.value)}
                    options={translatedStakeholderRoleOptions}
                    placeholder={t('projects.role')}
                    className="min-h-11 text-sm lg:min-h-9"
                  />
                  <Select
                    value={stakeholder.influence}
                    onChange={(e) => onStakeholderChange(index, 'influence', e.target.value)}
                    options={translatedInfluenceOptions}
                    placeholder={t('projects.influence')}
                    className="min-h-11 text-sm lg:min-h-9"
                  />
                </div>
              </div>
            </div>
          );
        })}
      </div>

      {!compact && (
        <Button
          type="button"
          variant="outline"
          size="sm"
          onClick={onAddStakeholder}
          leftIcon={<IconPlus className="h-4 w-4" />}
          className="mt-4 h-11 lg:h-8"
        >
          {t('projects.add_stakeholder')}
        </Button>
      )}
    </>
  );

  if (compact) {
    return <div className="space-y-3">{content}</div>;
  }

  return (
    <Card className="p-0">
      <CardHeader className="p-4 pb-0 sm:p-6 sm:pb-0">
        <CardTitle className="flex items-center gap-2">
          <IconUsers className="h-5 w-5 text-[var(--accent-default)]" />
          {t('projects.stakeholders')}
        </CardTitle>
        <p className="text-sm text-[var(--text-secondary)] mt-1 flex items-start gap-1">
          <IconInfoCircle className="h-4 w-4 mt-0 shrink-0" />
          <span>{t('projects.stakeholders_description')}</span>
        </p>
      </CardHeader>
      <CardContent className="p-4 sm:p-6">
        {content}
      </CardContent>
    </Card>
  );
});

StakeholdersSection.displayName = 'StakeholdersSection';

export default StakeholdersSection;
