import React from 'react';
import { useTranslation } from 'react-i18next';
import { IconSearch } from '@tabler/icons-react';
import { FilterField, FilterRow, Input, Select } from '@shared/ui';
import type { Department } from './types';

interface FiltersCardProps {
  filters: { search: string; department_id: string; status: string };
  departments: Department[];
  onFiltersChange: (filters: { search: string; department_id: string; status: string }) => void;
}

const FiltersCard: React.FC<FiltersCardProps> = ({ filters, departments, onFiltersChange }) => {
  const { t } = useTranslation();

  return (
    <FilterRow>
      <FilterField label={t('common.search')}>
        <Input
          placeholder={t('hr.search_employees_placeholder')}
          value={filters.search}
          onChange={(e) => onFiltersChange({ ...filters, search: e.target.value })}
          leftIcon={<IconSearch className="h-4 w-4" />}
        />
      </FilterField>
      <FilterField label={t('common.department')}>
        <Select
          value={filters.department_id}
          onChange={(e) => onFiltersChange({ ...filters, department_id: e.target.value })}
          options={[
            { value: '', label: t('hr.all_departments') },
            ...departments.map((dept) => ({
              value: String(dept.id),
              label: dept.name,
            })),
          ]}
        />
      </FilterField>
      <FilterField label={t('common.status')}>
        <Select
          value={filters.status}
          onChange={(e) => onFiltersChange({ ...filters, status: e.target.value })}
          options={[
            { value: '', label: t('hr.all_statuses') },
            { value: 'active', label: t('hr.status_active') },
            { value: 'on_leave', label: t('hr.status_on_leave') },
            { value: 'terminated', label: t('hr.status_terminated') },
          ]}
        />
      </FilterField>
    </FilterRow>
  );
};

export default FiltersCard;
