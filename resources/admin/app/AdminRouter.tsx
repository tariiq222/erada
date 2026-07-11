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
            <Route path="/organizations/*" element={<ProtectedPagePlaceholder titleKey="admin.organizations.title" />} />
            <Route path="/access/*" element={<ProtectedPagePlaceholder titleKey="admin.access.title" />} />
            <Route path="/roles/*" element={<ProtectedPagePlaceholder titleKey="admin.roles.title" />} />
            <Route path="/users/*" element={<ProtectedPagePlaceholder titleKey="admin.users.title" />} />
            <Route path="/activity-logs/*" element={<ProtectedPagePlaceholder titleKey="admin.activityLogs.title" />} />
            <Route path="/scoped-roles/audit-logs" element={<ProtectedPagePlaceholder titleKey="admin.scopedRolesAudit.title" />} />
            <Route path="/scope-types/*" element={<ProtectedPagePlaceholder titleKey="admin.scopeTypes.title" />} />
            <Route path="/departments/*" element={<ProtectedPagePlaceholder titleKey="admin.departments.title" />} />
            <Route path="/incident-types/*" element={<ProtectedPagePlaceholder titleKey="admin.incidentTypes.title" />} />
            <Route path="*" element={<NotFound />} />
          </Route>
        </Route>
      </Routes>
    </BrowserRouter>
  );
}
