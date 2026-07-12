/**
 * AccessHub — unified admin page for roles, members, governance, and audit.
 *
 * Replaces the distributed role, assignment-audit, and
 * the standalone /users/:id/access surfaces with a single tabbed screen.
 * Tabs are URL-driven (?tab=roles|members|governance|audit) so deep links work
 * and the active tab survives a full reload.
 */

import React from 'react';
import { Navigate, useSearchParams } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { Tabs, TabsList, TabsTrigger, TabsContent } from '@shared/ui';
import { IconShield, IconUsers, IconBuildingBank, IconHistory } from '@tabler/icons-react';
import { RolesList } from '../roles/RolesList';
import { GoverningDepartments } from '../roles/GoverningDepartments';
import AuthorizationAssignmentAuditLogs from '../authorization/AuthorizationAssignmentAuditLogs';
import { MembersPanel } from './MembersPanel';

const TABS = ['roles', 'members', 'governance', 'audit'] as const;
type Tab = (typeof TABS)[number];

const isTab = (v: string | null): v is Tab =>
  v !== null && (TABS as readonly string[]).includes(v);

export const AccessHub: React.FC = () => {
  const { t } = useTranslation();
  const [searchParams, setSearchParams] = useSearchParams();
  const rawTab = searchParams.get('tab');
  const tab: Tab = isTab(rawTab) ? rawTab : 'roles';

  if (rawTab !== null && !isTab(rawTab)) {
    return <Navigate to="/admin/access?tab=roles" replace />;
  }

  const setTab = (v: string) => {
    setSearchParams({ tab: v }, { replace: true });
  };

  return (
    <div className="p-6 space-y-6">
      <Tabs value={tab} defaultValue="roles" onValueChange={setTab}>
        <TabsList>
          <TabsTrigger value="roles" icon={<IconShield className="w-4 h-4" />}>
            {t('admin.access.tabs.roles', 'الأدوار')}
          </TabsTrigger>
          <TabsTrigger value="members" icon={<IconUsers className="w-4 h-4" />}>
            {t('admin.access.tabs.members', 'الأعضاء')}
          </TabsTrigger>
          <TabsTrigger value="governance" icon={<IconBuildingBank className="w-4 h-4" />}>
            {t('admin.access.tabs.governance', 'الحوكمة')}
          </TabsTrigger>
          <TabsTrigger value="audit" icon={<IconHistory className="w-4 h-4" />}>
            {t('admin.access.tabs.audit', 'السجل')}
          </TabsTrigger>
        </TabsList>

        <TabsContent value="roles">
          <RolesList embedded />
        </TabsContent>
        <TabsContent value="members">
          <MembersPanel embedded />
        </TabsContent>
        <TabsContent value="governance">
          <GoverningDepartments embedded />
        </TabsContent>
        <TabsContent value="audit">
          <AuthorizationAssignmentAuditLogs embedded />
        </TabsContent>
      </Tabs>
    </div>
  );
};

export default AccessHub;
