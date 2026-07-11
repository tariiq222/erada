import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import '../css/app.css';
import '@shared/config/i18n';
import { initSentry } from '@shared/lib/sentry';
import { AdminApp } from '@admin/app/AdminApp';

initSentry();

const rootElement = document.getElementById('admin-root');

if (!rootElement) {
  throw new Error('Admin root element was not found');
}

createRoot(rootElement).render(
  <StrictMode>
    <AdminApp />
  </StrictMode>,
);

requestAnimationFrame(() => {
  document.getElementById('admin-loader')?.remove();
});
