import React from 'react';
import { useTranslation } from 'react-i18next';
import { IconSearch } from '@tabler/icons-react';
import { FilterField, FilterRow, Button, Input, Select } from '@shared/ui';
import { statusLabels, typeLabels } from './constants';

interface FiltersCardProps {
  filters: { search: string; type: string; status: string };
  onFiltersChange: (filters: { search: string; type: string; status: string }) => void;
  onSearch: (e: React.FormEvent) => void;
  onClose: () => void;
}

const FiltersCard: React.FC<FiltersCardProps> = ({
  filters,
  onFiltersChange,
  onSearch,
  onClose,
}) => {
  const { t } = useTranslation();
  return (
    <div className="animate-in slide-in-from-top-2 duration-200">
      <form onSubmit={onSearch}>
        <FilterRow onClear={onClose} clearLabel={t('common.filter_results')}>
          <FilterField label="">
            <Input
              placeholder={t('surveys.search_placeholder')}
              value={filters.search}
              onChange={(e) => onFiltersChange({ ...filters, search: e.target.value })}
              leftIcon={<IconSearch className="h-4 w-4" />}
            />
          </FilterField>
          <FilterField label="">
            <Select
              value={filters.type}
              onChange={(e) => onFiltersChange({ ...filters, type: e.target.value })}
              options={[
                { value: '', label: t('surveys.all_types') },
                ...Object.entries(typeLabels).map(([value, label]) => ({
                  value,
                  label: t(label),
                })),
              ]}
            />
          </FilterField>
          <FilterField label="">
            <Select
              value={filters.status}
              onChange={(e) => onFiltersChange({ ...filters, status: e.target.value })}
              options={[
                { value: '', label: t('common.all_statuses') },
                ...Object.entries(statusLabels).map(([value, label]) => ({
                  value,
                  label: t(label),
                })),
              ]}
            />
          </FilterField>
          <div className="shrink-0">
            <Button
              type="submit"
              variant="secondary"
              size="sm"
              leftIcon={<IconSearch className="h-4 w-4" />}
            >
              {t('common.search')}
            </Button>
          </div>
        </FilterRow>
      </form>
    </div>
  );
};

export default FiltersCard;
