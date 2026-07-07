import React from 'react';
import { useTranslation } from 'react-i18next';
import { IconSearch } from '@tabler/icons-react';
import { Button, Input, Select, FilterRow, FilterField } from '@shared/ui';
import { statusLabels, priorityLabels } from './constants';
import type { ProgramOption } from './types';

interface FiltersCardProps {
  filters: { search: string; status: string; priority: string; program_id: string };
  onFiltersChange: (filters: { search: string; status: string; priority: string; program_id: string }) => void;
  onSearch: (e: React.FormEvent) => void;
  onClose: () => void;
  programs: ProgramOption[];
}

const FiltersCard: React.FC<FiltersCardProps> = ({
  filters,
  onFiltersChange,
  onSearch,
  onClose,
  programs,
}) => {
  const { t } = useTranslation();

  return (
    <FilterRow onClear={onClose} clearLabel={t('common.close')}>
      <form onSubmit={onSearch} className="flex flex-wrap items-center gap-3 flex-1">
        <FilterField label="" grow>
          <Input
            placeholder={t('projects.search_placeholder')}
            value={filters.search}
            onChange={(e) => onFiltersChange({ ...filters, search: e.target.value })}
            leftIcon={<IconSearch className="h-4 w-4" />}
          />
        </FilterField>
        <FilterField label={t('common.status')} grow={false}>
          <Select
            value={filters.status}
            onChange={(e) => onFiltersChange({ ...filters, status: e.target.value })}
            options={[
              { value: '', label: t('projects.all_statuses') },
              ...Object.entries(statusLabels).map(([value, labelKey]) => ({
                value,
                label: t(labelKey),
              })),
            ]}
          />
        </FilterField>
        <FilterField label={t('common.priority')} grow={false}>
          <Select
            value={filters.priority}
            onChange={(e) => onFiltersChange({ ...filters, priority: e.target.value })}
            options={[
              { value: '', label: t('projects.all_priorities') },
              ...Object.entries(priorityLabels).map(([value, labelKey]) => ({
                value,
                label: t(labelKey),
              })),
            ]}
          />
        </FilterField>
        <FilterField label={t('projects.program')} grow={false}>
          <Select
            value={filters.program_id}
            onChange={(e) => onFiltersChange({ ...filters, program_id: e.target.value })}
            options={[
              { value: '', label: t('projects.all_initiatives') },
              { value: 'none', label: t('projects.independent_projects') },
              ...programs.map((prog) => ({
                value: prog.id.toString(),
                label: `${prog.code} - ${prog.name}`,
              })),
            ]}
          />
        </FilterField>
        <div className="shrink-0">
          <Button type="submit" variant="secondary" size="sm" leftIcon={<IconSearch className="h-4 w-4" />}>
            {t('common.search')}
          </Button>
        </div>
      </form>
    </FilterRow>
  );
};

export default FiltersCard;
