import React, { useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import {IconNetwork, IconShieldCheck, IconInfoCircle} from '@tabler/icons-react';
import { departmentsApi } from '@entities/hr';
import {
  Card,
  CardContent,
  PageHeader,
  Tabs,
  TabsList,
  TabsTrigger,
  TabsContent,
  Skeleton,
} from '@shared/ui';
import DepartmentTeamSection from './components/DepartmentTeamSection';

const DepartmentView: React.FC = () => {
  const { t } = useTranslation();
  const { id } = useParams<{ id: string }>();
  const deptId = Number(id);
  const [department, setDepartment] = useState<any>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    const fetch = async () => {
      try {
        const res: any = await departmentsApi.getOne(deptId);
        setDepartment(res?.data ?? res);
      } catch (e) {
        console.error('Failed to fetch department', e);
      } finally {
        setLoading(false);
      }
    };
    if (!Number.isNaN(deptId)) fetch();
    else setLoading(false);
  }, [deptId]);

  if (loading) {
    return (
      <div className="space-y-6">
        <div className="space-y-2">
          <Skeleton className="h-7 w-64" variant="rounded" />
          <Skeleton className="h-4 w-80" variant="rounded" />
        </div>
        <Skeleton className="h-64 w-full" variant="rounded" />
      </div>
    );
  }

  if (!department) {
    return (
      <div className="text-center py-12">
        <h2 className="text-xl font-semibold text-[var(--text-primary)]">
          {t('hr.department_not_found')}
        </h2>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      <PageHeader
        title={department?.name || t('hr.department_details')}
        subtitle={department?.code || t('hr.departmentRoles.page_subtitle')}
        icon={IconNetwork}
        iconTone="admin"
      />

      <Tabs defaultValue="roles">
        <TabsList>
          <TabsTrigger value="roles" icon={<IconShieldCheck className="h-4 w-4" />}>
            {t('hr.departmentRoles.tab_title')}
          </TabsTrigger>
          <TabsTrigger value="overview" icon={<IconInfoCircle className="h-4 w-4" />}>
            {t('hr.departmentRoles.overview_tab')}
          </TabsTrigger>
        </TabsList>

        <TabsContent value="roles">
          <DepartmentTeamSection deptId={deptId} />
        </TabsContent>

        <TabsContent value="overview">
          <Card className="border border-[var(--border-default)]">
            <CardContent className="p-6 space-y-3">
              <div className="flex justify-between text-sm">
                <span className="text-[var(--text-secondary)]">{t('hr.manager')}</span>
                <span className="text-[var(--text-primary)] font-medium">
                  {department?.manager?.name || '-'}
                </span>
              </div>
              <div className="flex justify-between text-sm">
                <span className="text-[var(--text-secondary)]">{t('hr.level')}</span>
                <span className="text-[var(--text-primary)] font-medium">
                  {department?.level_name || '-'}
                </span>
              </div>
              <div className="flex justify-between text-sm">
                <span className="text-[var(--text-secondary)]">{t('hr.employees')}</span>
                <span className="text-[var(--text-primary)] font-medium">
                  {department?.employees_count ?? 0}
                </span>
              </div>
            </CardContent>
          </Card>
        </TabsContent>
      </Tabs>
    </div>
  );
};

export default DepartmentView;
