import React from 'react';
import { useTranslation } from 'react-i18next';
import { IconSearch } from '@tabler/icons-react';
import { FilterField, FilterRow, Input, Select } from '@shared/ui';
import { roleLabels } from './constants';
import type { Department } from './types';

interface FiltersCardProps {
  filters: {
    search: string;
    department_id: string;
    role: string;
    is_active: string;
  };
  departments: Department[];
  onFiltersChange: (filters: {
    search: string;
    department_id: string;
    role: string;
    is_active: string;
  }) => void;
  onClose: () => void;
}

const FiltersCard: React.FC<FiltersCardProps> = ({
  filters,
  departments,
  onFiltersChange,
  onClose,
}) => {
  const { t } = useTranslation();

  return (
    <FilterRow onClear={onClose} clearLabel={t('common.close')}>
      <FilterField label={t('common.search')}>
        <Input
          placeholder={t('users.search_placeholder')}
          value={filters.search}
          onChange={(e) => onFiltersChange({ ...filters, search: e.target.value })}
          leftIcon={<IconSearch className="h-4 w-4" />}
        />
      </FilterField>
      <FilterField label={t('users.department')}>
        <Select
          value={filters.department_id}
          onChange={(e) => onFiltersChange({ ...filters, department_id: e.target.value })}
          options={[
            { value: '', label: t('users.all_departments') },
            ...departments.map((dept) => ({
              value: String(dept.id),
              label: dept.name,
            })),
          ]}
        />
      </FilterField>
      <FilterField label={t('users.role')}>
        <Select
          value={filters.role}
          onChange={(e) => onFiltersChange({ ...filters, role: e.target.value })}
          options={[
            { value: '', label: t('users.all_roles') },
            ...Object.entries(roleLabels).map(([value, label]) => ({
              value,
              label: t(label),
            })),
          ]}
        />
      </FilterField>
      <FilterField label={t('common.status')}>
        <Select
          value={filters.is_active}
          onChange={(e) => onFiltersChange({ ...filters, is_active: e.target.value })}
          options={[
            { value: '', label: t('users.all_statuses') },
            { value: '1', label: t('common.active') },
            { value: '0', label: t('common.inactive') },
          ]}
        />
      </FilterField>
    </FilterRow>
  );
};

export default FiltersCard;
