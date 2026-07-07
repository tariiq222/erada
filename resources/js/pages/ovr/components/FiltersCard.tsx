import React from 'react';
import { useTranslation } from 'react-i18next';
import { IconSearch } from '@tabler/icons-react';
import { FilterField, FilterRow, Input, Select } from '@shared/ui';
import type { Category } from './types';

interface FiltersCardProps {
  filters: { search: string; category_id: string; severity: string; status: string };
  categories: Category[];
  onFiltersChange: (filters: any) => void;
}

const FiltersCard: React.FC<FiltersCardProps> = ({ filters, categories, onFiltersChange }) => {
  const { t } = useTranslation();
  return (
    <FilterRow
      onClear={() => onFiltersChange({ search: '', category_id: '', severity: '', status: '' })}
      clearLabel={t('common.clear_filters')}
    >
      <FilterField label={t('common.search')}>
        <Input
          placeholder={t('ovr.search_placeholder')}
          value={filters.search}
          onChange={(e) => onFiltersChange({ ...filters, search: e.target.value })}
          leftIcon={<IconSearch className="h-4 w-4" />}
        />
      </FilterField>
      <FilterField label={t('ovr.category')}>
        <Select
          value={filters.category_id}
          onChange={(e) => onFiltersChange({ ...filters, category_id: e.target.value })}
          options={[
            { value: '', label: t('ovr.all_categories') },
            ...(Array.isArray(categories) ? categories : []).map((cat) => ({
              value: String(cat.id),
              label: cat.name,
            })),
          ]}
        />
      </FilterField>
      <FilterField label={t('ovr.severity')}>
        <Select
          value={filters.severity}
          onChange={(e) => onFiltersChange({ ...filters, severity: e.target.value })}
          options={[
            { value: '', label: t('ovr.all_severities') },
            { value: 'low', label: t('ovr.severity_low') },
            { value: 'medium', label: t('ovr.severity_medium') },
            { value: 'high', label: t('ovr.severity_high') },
            { value: 'critical', label: t('ovr.severity_critical') },
          ]}
        />
      </FilterField>
      <FilterField label={t('common.status')}>
        <Select
          value={filters.status}
          onChange={(e) => onFiltersChange({ ...filters, status: e.target.value })}
          options={[
            { value: '', label: t('common.all_statuses') },
            { value: 'draft', label: t('ovr.status_draft') },
            { value: 'new', label: t('ovr.status_new') },
            { value: 'under_review', label: t('ovr.status_under_review') },
            { value: 'pending_info', label: t('ovr.status_pending_info') },
            { value: 'in_progress', label: t('ovr.status_in_progress') },
            { value: 'resolved', label: t('ovr.status_resolved') },
            { value: 'closed', label: t('ovr.status_closed') },
            { value: 'rejected', label: t('ovr.status_rejected') },
            { value: 'archived', label: t('ovr.status_archived') },
          ]}
        />
      </FilterField>
    </FilterRow>
  );
};

export default FiltersCard;
