import { AdminProviders } from '@admin/app/AdminProviders';
import { AdminRouter } from '@admin/app/AdminRouter';

export function AdminApp() {
  return (
    <AdminProviders>
      <AdminRouter />
    </AdminProviders>
  );
}
