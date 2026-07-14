import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom';
import { SuperAdminBoundary } from '@admin/app/SuperAdminBoundary';
import { OrgSuperOrSuperBoundary } from '@admin/app/OrgSuperOrSuperBoundary';
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
import { UsersPage } from '@admin/pages/users/UsersPage';
import { UserForm } from '@admin/pages/users/UserForm';
import { UserDetails } from '@admin/pages/users/UserDetails';
import { DepartmentsPage } from '@admin/pages/departments/DepartmentsPage';
import { DepartmentForm } from '@admin/pages/departments/DepartmentForm';
import { DepartmentDetails } from '@admin/pages/departments/DepartmentDetails';
import { IncidentTypesPage } from '@admin/pages/incident-types/IncidentTypesPage';
import { OrganizationSettingsPage } from '@admin/pages/organization-settings/OrganizationSettingsPage';

export function AdminRouter() {
  return (
    <BrowserRouter>
      <Routes>
        <Route path="/login" element={<Login />} />
        <Route path="/verify-2fa" element={<TwoFactorVerification />} />

        {/*
          Platform-only routes — only `is_super_admin === true` may reach these.
          These are the routes whose backend endpoints are gated by super-admin
          capabilities (organizations, global role definitions, scope types,
          platform-wide audit, incident types, governance rules).
        */}
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
            <Route path="/access/governance" element={<GovernanceRulesPage />} />
            <Route path="/roles" element={<RolesPage />} />
            <Route path="/roles/new" element={<RoleForm />} />
            <Route path="/roles/governing-departments" element={<Navigate to="/access/governance" replace />} />
            <Route path="/roles/:roleId" element={<RoleForm />} />
            <Route path="/roles/:roleId/edit" element={<RoleForm />} />
            <Route path="/scope-types" element={<ScopeTypesPage />} />
            <Route path="/incident-types" element={<IncidentTypesPage />} />
          </Route>
        </Route>

        {/*
          Org-admin-reachable routes — reachable by `is_super_admin === true`
          OR `is_organization_super_admin === true`. These are the routes
          whose backend endpoints are gated by the OrgSuper surface
          (same-org user lifecycle, departments, organization-scoped access
          summary, org-scoped activity logs, scoped-role audit). The catch-all
          lives here so that an org-super navigating to an unknown URL sees
          the standard 404 chrome rather than leaking into the super-only
          fallback chain.
        */}
        <Route element={<OrgSuperOrSuperBoundary />}>
          <Route element={<AdminLayout />}>
            <Route index element={<Navigate to="/users" replace />} />
            <Route path="/users" element={<UsersPage />} />
            <Route path="/users/new" element={<UserForm />} />
            <Route path="/users/:userId" element={<UserDetails />} />
            <Route path="/users/:userId/edit" element={<UserForm />} />
            <Route path="/departments" element={<DepartmentsPage />} />
            <Route path="/departments/new" element={<DepartmentForm />} />
            <Route path="/departments/:departmentId" element={<DepartmentDetails />} />
            <Route path="/departments/:departmentId/edit" element={<DepartmentForm />} />
            <Route path="/access" element={<AccessPage />} />
            <Route path="/activity-logs" element={<ActivityLogsPage />} />
            <Route path="/scoped-roles/audit-logs" element={<ScopedRoleAuditPage />} />
            <Route path="/settings" element={<OrganizationSettingsPage />} />
            <Route path="*" element={<NotFound />} />
          </Route>
        </Route>
      </Routes>
    </BrowserRouter>
  );
}
