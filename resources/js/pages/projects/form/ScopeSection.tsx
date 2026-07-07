import { memo } from 'react';
import { useTranslation } from 'react-i18next';
import { Card, CardHeader, CardTitle, CardContent } from '@shared/ui/Card';
import { Button } from '@shared/ui/Button';
import { IconButton } from '@shared/ui/IconButton';
import { Input } from '@shared/ui/Input';
import { FieldHelp } from '@shared/ui/FieldHelp';
import {IconTarget, IconPlus, IconTrash} from '@tabler/icons-react';

interface ScopeSectionProps {
  inScope: string[];
  outOfScope: string[];
  onInScopeChange: (index: number, value: string) => void;
  onOutOfScopeChange: (index: number, value: string) => void;
  onAddInScope: () => void;
  onAddOutOfScope: () => void;
  onRemoveInScope: (index: number) => void;
  onRemoveOutOfScope: (index: number) => void;
  compact?: boolean;
}

const ScopeSection = memo<ScopeSectionProps>(({
  inScope,
  outOfScope,
  onInScopeChange,
  onOutOfScopeChange,
  onAddInScope,
  onAddOutOfScope,
  onRemoveInScope,
  onRemoveOutOfScope,
  compact = false,
}) => {
  const { t } = useTranslation();

  if (compact) {
    return (
      <div className="grid grid-cols-1 gap-3 lg:grid-cols-2">
        <div className="rounded-lg border border-[var(--border-default)] bg-[var(--surface-base)] p-3">
          <div className="flex items-center justify-between gap-3 mb-2">
            <h3 className="text-sm font-semibold text-[var(--text-primary)] flex items-center gap-2">
              <span className="h-2 w-2 rounded-full bg-[var(--status-success)]"></span>
              {t('projects.in_scope')}
              <FieldHelp content={t('projects.help.in_scope')} />
            </h3>
            <Button
              type="button"
              variant="ghost"
              size="sm"
              onClick={onAddInScope}
              leftIcon={<IconPlus className="h-3.5 w-3.5" />}
              className="h-11 lg:h-7"
            >
              {t('common.add')}
            </Button>
          </div>
          <div className="space-y-2">
            {inScope.map((item, index) => {
              const inputId = `project-scope-compact-in-${index}`;

              return (
                <div key={index} className="flex items-center gap-2">
                  <label htmlFor={inputId} className="sr-only">
                    {t('projects.in_scope')} {index + 1}
                  </label>
                  <Input
                    id={inputId}
                    value={item}
                    onChange={(e) => onInScopeChange(index, e.target.value)}
                    placeholder={t('projects.enter_item')}
                    className="min-h-11 flex-1 text-sm lg:min-h-9"
                  />
                  {inScope.length > 1 && (
                    <IconButton
                      type="button"
                      variant="dangerStrong"
                      size="xs"
                      onClick={() => onRemoveInScope(index)}
                      aria-label={t('common.delete')}
                      className="h-11 w-11 shrink-0 lg:h-7 lg:w-7"
                    >
                      <IconTrash className="h-3.5 w-3.5" />
                    </IconButton>
                  )}
                </div>
              );
            })}
          </div>
        </div>

        <div className="rounded-lg border border-[var(--border-default)] bg-[var(--surface-base)] p-3">
          <div className="flex items-center justify-between gap-3 mb-2">
            <h3 className="text-sm font-semibold text-[var(--text-primary)] flex items-center gap-2">
              <span className="h-2 w-2 rounded-full bg-[var(--status-danger)]"></span>
              {t('projects.out_of_scope')}
              <FieldHelp content={t('projects.help.out_of_scope')} />
            </h3>
            <Button
              type="button"
              variant="ghost"
              size="sm"
              onClick={onAddOutOfScope}
              leftIcon={<IconPlus className="h-3.5 w-3.5" />}
              className="h-11 lg:h-7"
            >
              {t('common.add')}
            </Button>
          </div>
          <div className="space-y-2">
            {outOfScope.map((item, index) => {
              const inputId = `project-scope-compact-out-${index}`;

              return (
                <div key={index} className="flex items-center gap-2">
                  <label htmlFor={inputId} className="sr-only">
                    {t('projects.out_of_scope')} {index + 1}
                  </label>
                  <Input
                    id={inputId}
                    value={item}
                    onChange={(e) => onOutOfScopeChange(index, e.target.value)}
                    placeholder={t('projects.enter_item')}
                    className="min-h-11 flex-1 text-sm lg:min-h-9"
                  />
                  {outOfScope.length > 1 && (
                    <IconButton
                      type="button"
                      variant="dangerStrong"
                      size="xs"
                      onClick={() => onRemoveOutOfScope(index)}
                      aria-label={t('common.delete')}
                      className="h-11 w-11 shrink-0 lg:h-7 lg:w-7"
                    >
                      <IconTrash className="h-3.5 w-3.5" />
                    </IconButton>
                  )}
                </div>
              );
            })}
          </div>
        </div>
      </div>
    );
  }

  return (
    <Card className="p-0">
      <CardHeader className="p-4 pb-0 sm:p-6 sm:pb-0">
        <CardTitle className="flex items-center gap-2">
          <IconTarget className="h-5 w-5 text-[var(--accent-default)]" />
          {t('projects.scope')}
        </CardTitle>
      </CardHeader>
      <CardContent className="p-4 sm:p-6">
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
          <div className="p-4 border border-[var(--border-default)] rounded-lg bg-[var(--surface-base)]">
            <h3 className="text-sm font-semibold text-[var(--text-primary)] mb-3 flex items-center gap-2">
              <span className="h-2 w-2 rounded-full bg-[var(--status-success)]"></span>
              {t('projects.in_scope')}
            </h3>
            <div className="space-y-2">
              {inScope.map((item, index) => {
                const inputId = `project-scope-in-${index}`;

                return (
                  <div key={index} className="flex items-center gap-2">
                    <label htmlFor={inputId} className="sr-only">
                      {t('projects.in_scope')} {index + 1}
                    </label>
                    <Input
                      id={inputId}
                      value={item}
                      onChange={(e) => onInScopeChange(index, e.target.value)}
                      placeholder={t('projects.enter_item')}
                      className="min-h-11 flex-1 text-sm lg:min-h-9"
                    />
                    {inScope.length > 1 && (
                      <IconButton
                        type="button"
                        variant="dangerStrong"
                        size="xs"
                        onClick={() => onRemoveInScope(index)}
                        aria-label={t('common.delete')}
                        className="h-11 w-11 shrink-0 lg:h-7 lg:w-7"
                      >
                        <IconTrash className="h-3.5 w-3.5" />
                      </IconButton>
                    )}
                  </div>
                );
              })}
              <Button
                type="button"
                variant="ghost"
                size="sm"
                onClick={onAddInScope}
                leftIcon={<IconPlus className="h-3.5 w-3.5" />}
                className="h-11 lg:h-8"
              >
                {t('common.add')}
              </Button>
            </div>
          </div>
          <div className="p-4 border border-[var(--border-default)] rounded-lg bg-[var(--surface-base)]">
            <h3 className="text-sm font-semibold text-[var(--text-primary)] mb-3 flex items-center gap-2">
              <span className="h-2 w-2 rounded-full bg-[var(--status-danger)]"></span>
              {t('projects.out_of_scope')}
            </h3>
            <div className="space-y-2">
              {outOfScope.map((item, index) => {
                const inputId = `project-scope-out-${index}`;

                return (
                  <div key={index} className="flex items-center gap-2">
                    <label htmlFor={inputId} className="sr-only">
                      {t('projects.out_of_scope')} {index + 1}
                    </label>
                    <Input
                      id={inputId}
                      value={item}
                      onChange={(e) => onOutOfScopeChange(index, e.target.value)}
                      placeholder={t('projects.enter_item')}
                      className="min-h-11 flex-1 text-sm lg:min-h-9"
                    />
                    {outOfScope.length > 1 && (
                      <IconButton
                        type="button"
                        variant="dangerStrong"
                        size="xs"
                        onClick={() => onRemoveOutOfScope(index)}
                        aria-label={t('common.delete')}
                        className="h-11 w-11 shrink-0 lg:h-7 lg:w-7"
                      >
                        <IconTrash className="h-3.5 w-3.5" />
                      </IconButton>
                    )}
                  </div>
                );
              })}
              <Button
                type="button"
                variant="ghost"
                size="sm"
                onClick={onAddOutOfScope}
                leftIcon={<IconPlus className="h-3.5 w-3.5" />}
                className="h-11 lg:h-8"
              >
                {t('common.add')}
              </Button>
            </div>
          </div>
        </div>
      </CardContent>
    </Card>
  );
});

ScopeSection.displayName = 'ScopeSection';

export default ScopeSection;
