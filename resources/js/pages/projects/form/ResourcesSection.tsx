import { memo } from 'react';
import { useTranslation } from 'react-i18next';
import { Card, CardHeader, CardTitle, CardContent } from '@shared/ui/Card';
import { Textarea } from '@shared/ui/Textarea';
import {IconBriefcase} from '@tabler/icons-react';

interface ResourcesSectionProps {
  humanResources: string;
  technicalResources: string;
  financialResources: string;
  onHumanResourcesChange: (value: string) => void;
  onTechnicalResourcesChange: (value: string) => void;
  onFinancialResourcesChange: (value: string) => void;
  compact?: boolean;
  /** Force the three resource fields into a single column (e.g. inside a half-width card). */
  stacked?: boolean;
}

const ResourcesSection = memo<ResourcesSectionProps>(({
  humanResources,
  technicalResources,
  financialResources,
  onHumanResourcesChange,
  onTechnicalResourcesChange,
  onFinancialResourcesChange,
  compact = false,
  stacked = false,
}) => {
  const { t } = useTranslation();

  const layoutClassName = stacked
    ? 'grid grid-cols-1 gap-3'
    : compact
      ? 'grid grid-cols-1 gap-3 md:grid-cols-3'
      : 'grid grid-cols-1 md:grid-cols-3 gap-6';

  const content = (
    <div className={layoutClassName}>
      <Textarea
        label={t('projects.human_resources')}
        help={t('projects.help.human_resources')}
        value={humanResources}
        onChange={(e) => onHumanResourcesChange(e.target.value)}
        placeholder={t('projects.human_resources_placeholder')}
        rows={compact ? 2 : 3}
        className="resize-none text-sm"
      />
      <Textarea
        label={t('projects.technical_resources')}
        help={t('projects.help.technical_resources')}
        value={technicalResources}
        onChange={(e) => onTechnicalResourcesChange(e.target.value)}
        placeholder={t('projects.technical_resources_placeholder')}
        rows={compact ? 2 : 3}
        className="resize-none text-sm"
      />
      <Textarea
        label={t('projects.financial_resources')}
        help={t('projects.help.financial_resources')}
        value={financialResources}
        onChange={(e) => onFinancialResourcesChange(e.target.value)}
        placeholder={t('projects.financial_resources_placeholder')}
        rows={compact ? 2 : 3}
        className="resize-none text-sm"
      />
    </div>
  );

  if (compact) {
    return content;
  }

  return (
    <Card className="p-0">
      <CardHeader className="p-4 pb-0 sm:p-6 sm:pb-0">
        <CardTitle className="flex items-center gap-2">
          <IconBriefcase className="h-5 w-5 text-[var(--accent-default)]" />
          {t('projects.resources_and_support')}
        </CardTitle>
      </CardHeader>
      <CardContent className="p-4 sm:p-6">
        {content}
      </CardContent>
    </Card>
  );
});

ResourcesSection.displayName = 'ResourcesSection';

export default ResourcesSection;
