import React from 'react';
import { useTranslation } from 'react-i18next';
import {IconUsers, IconCircleCheck, IconCircleX, IconShield} from '@tabler/icons-react';
import { StatCard } from '@shared/ui';
import type { User } from './types';

interface StatsCardsProps {
  users: User[];
  total: number;
}

const StatsCards: React.FC<StatsCardsProps> = ({ users, total }) => {
  const { t } = useTranslation();

  return (
    <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
      <StatCard
        label={t('users.total_users')}
        value={total}
        icon={IconUsers}
        color="accent"
      />
      <StatCard
        label={t('common.active')}
        value={users.filter(u => u.is_active).length}
        icon={IconCircleCheck}
        color="success"
      />
      <StatCard
        label={t('common.inactive')}
        value={users.filter(u => !u.is_active).length}
        icon={IconCircleX}
        color="danger"
      />
      <StatCard
        label={t('users.admins')}
        value={users.filter(u => u.roles.includes('admin') || u.roles.includes('super_admin')).length}
        icon={IconShield}
        color="accent"
      />
    </div>
  );
};

export default StatsCards;
