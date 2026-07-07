import React from 'react';
import { useTranslation } from 'react-i18next';
import {IconNetwork, IconBuilding} from '@tabler/icons-react';
import { Card, CardContent } from '@shared/ui';
import type { Department } from './departmentTypes';

interface DepartmentStatsCardsProps {
  departments: Department[];
  total: number;
}

const DepartmentStatsCards: React.FC<DepartmentStatsCardsProps> = ({ departments, total }) => {
  const { t } = useTranslation();

  return (
    <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
      <Card className="border border-[var(--border-default)] hover:shadow-md transition-shadow duration-300">
        <CardContent className="p-4">
          <div className="flex items-center justify-between">
            <div>
              <p className="text-xs font-medium text-[var(--text-secondary)]">{t('hr.total_departments')}</p>
              <p className="text-2xl font-bold text-[var(--text-primary)] mt-1">{total}</p>
            </div>
            <div className="h-10 w-10 rounded-lg bg-[var(--accent-default)] flex items-center justify-center">
              <IconNetwork className="h-5 w-5 text-[var(--text-inverse)]" />
            </div>
          </div>
        </CardContent>
      </Card>
      <Card className="border border-[var(--border-default)] hover:shadow-md transition-shadow duration-300">
        <CardContent className="p-4">
          <div className="flex items-center justify-between">
            <div>
              <p className="text-xs font-medium text-[var(--text-secondary)]">{t('hr.active_departments')}</p>
              <p className="text-2xl font-bold text-[var(--text-primary)] mt-1">
                {departments.filter(d => d.is_active).length}
              </p>
            </div>
            <div className="h-10 w-10 rounded-lg bg-[var(--status-success)] flex items-center justify-center">
              <IconBuilding className="h-5 w-5 text-[var(--text-inverse)]" />
            </div>
          </div>
        </CardContent>
      </Card>
    </div>
  );
};

export default DepartmentStatsCards;
