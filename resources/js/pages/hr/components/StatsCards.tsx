import React from 'react';
import { useTranslation } from 'react-i18next';
import {IconUserCircle, IconCalendar} from '@tabler/icons-react';
import { StatCard } from '@shared/ui';
import type { Employee } from './types';

interface StatsCardsProps {
  employees: Employee[];
  total: number;
}

const StatsCards: React.FC<StatsCardsProps> = ({ employees, total }) => {
  const { t } = useTranslation();

  const resolveStatus = (e: Employee): string | null => {
    const fromProfile = e.employee_profile?.employment_status;
    if (fromProfile) return fromProfile;
    const legacy = (e as unknown as { status?: string }).status;
    return legacy ?? null;
  };

  return (
    <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
      <StatCard
        label={t('hr.total_employees')}
        value={total}
        icon={IconUserCircle}
        color="accent"
      />
      <StatCard
        label={t('hr.status_active')}
        value={employees.filter(e => resolveStatus(e) === 'active').length}
        icon={IconUserCircle}
        color="success"
      />
      <StatCard
        label={t('hr.status_on_leave')}
        value={employees.filter(e => resolveStatus(e) === 'on_leave').length}
        icon={IconCalendar}
        color="warning"
      />
      <StatCard
        label={t('hr.status_terminated')}
        value={employees.filter(e => resolveStatus(e) === 'terminated').length}
        icon={IconUserCircle}
        color="danger"
      />
    </div>
  );
};

export default StatsCards;
