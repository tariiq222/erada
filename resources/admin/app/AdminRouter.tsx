import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { SuperAdminBoundary } from '@admin/app/SuperAdminBoundary';
import { Login } from '@admin/pages/Login';
import { TwoFactorVerification } from '@admin/pages/TwoFactorVerification';
import { NotFound } from '@admin/pages/NotFound';
import { AdminLayout } from '@admin/widgets/admin-shell/AdminLayout';
import { Overview } from '@admin/pages/overview/Overview';
import { SecurityAlerts } from '@admin/pages/security-alerts/SecurityAlerts';
import { AuditRecent } from '@admin/pages/audit-recent/AuditRecent';
import { OrganizationsPage } from '@admin/pages/organizations/OrganizationsPage';
import { OrganizationForm } from '@admin/pages/organizations/OrganizationForm';
import { OrganizationDetails } from '@admin/pages/organizations/OrganizationDetails';
import { RolesPage } from '@admin/pages/roles/RolesPage';
import { RoleForm } from '@admin/pages/roles/RoleForm';
import { AccessPage } from '@admin/pages/access/AccessPage';
import { GovernanceRulesPage } from '@admin/pages/access/GovernanceRulesPage';
import { ActivityLogsPage } from '@admin/pages/activity-logs/ActivityLogsPage';
import { ScopedRoleAuditPage } from '@admin/pages/scoped-roles/ScopedRoleAuditPage';
import { ScopeTypesPage } from '@admin/pages/scope-types/ScopeTypesPage';

function ProtectedPagePlaceholder({ titleKey }: { titleKey: string }) {
  const { t } = useTranslation();

  return (
    <section className="mx-auto max-w-6xl p-5 sm:p-8" data-testid="admin-protected-page">
      <p className="mb-2 text-xs font-semibold text-[var(--accent-default)]">
        {t('admin.shell.brand')}
      </p>
      <h1 className="text-2xl font-bold text-[var(--text-primary)]">{t(titleKey)}</h1>
    </section>
  );
}

export function AdminRouter() {
  return (
    <BrowserRouter>
      <Routes>
        <Route path="/login" element={<Login />} />
        <Route path="/verify-2fa" element={<TwoFactorVerification />} />

        <Route element={<SuperAdminBoundary />}>
          <Route element={<AdminLayout />}>
            <Route index element={<Navigate to="/overview" replace />} />
            <Route path="/overview" element={<Overview />} />
            <Route path="/security/alerts" element={<SecurityAlerts />} />
            <Route path="/audit/recent" element={<AuditRecent />} />
            <Route path="/organizations" element={<OrganizationsPage />} />
            <Route path="/organizations/new" element={<OrganizationForm />} />
            <Route path="/organizations/:organizationId" element={<OrganizationDetails />} />
            <Route path="/organizations/:organizationId/edit" element={<OrganizationForm />} />
            <Route path="/access" element={<AccessPage />} />
            <Route path="/access/governance" element={<GovernanceRulesPage />} />
            <Route path="/roles" element={<RolesPage />} />
            <Route path="/roles/new" element={<RoleForm />} />
            <Route path="/roles/governing-departments" element={<Navigate to="/access/governance" replace />} />
            <Route path="/roles/:roleId" element={<RoleForm />} />
            <Route path="/roles/:roleId/edit" element={<RoleForm />} />
            <Route path="/users/*" element={<ProtectedPagePlaceholder titleKey="admin.users.title" />} />
            <Route path="/activity-logs" element={<ActivityLogsPage />} />
            <Route path="/scoped-roles/audit-logs" element={<ScopedRoleAuditPage />} />
            <Route path="/scope-types" element={<ScopeTypesPage />} />
            <Route path="/departments/*" element={<ProtectedPagePlaceholder titleKey="admin.departments.title" />} />
            <Route path="/incident-types/*" element={<ProtectedPagePlaceholder titleKey="admin.incidentTypes.title" />} />
            <Route path="*" element={<NotFound />} />
          </Route>
        </Route>
      </Routes>
    </BrowserRouter>
  );
}
