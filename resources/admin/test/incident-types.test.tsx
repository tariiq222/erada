import React from 'react';
import userEvent from '@testing-library/user-event';
import { render, screen, waitFor, within } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { User } from '@shared/types';
import i18n from '@shared/config/i18n';
import { api } from '@shared/api/client';
import { AdminRouter } from '@admin/app/AdminRouter';
import { adminApi } from '@admin/api/adminApi';

const authState = { user: { id: 1, name: 'Control Admin', email: 'admin@example.test', department_id: null, phone: null, extension: null, job_title: null, is_active: true, roles: ['super_admin'], organization_id: 17 } satisfies User & { organization_id: number }, isLoading: false, isAuthenticated: true };
vi.mock('@shared/contexts/AuthContext', () => ({ AuthProvider: ({ children }: { children: React.ReactNode }) => children, useAuth: () => ({ ...authState, logout: vi.fn(), refreshUser: vi.fn() }) }));
vi.mock('@shared/contexts/LocaleContext', () => ({ LocaleProvider: ({ children }: { children: React.ReactNode }) => children, useLocale: () => ({ locale: 'ar', direction: 'rtl', setLocale: vi.fn() }) }));
vi.mock('@shared/contexts/ThemeContext', () => ({ ThemeProvider: ({ children }: { children: React.ReactNode }) => children, useTheme: () => ({ resolvedTheme: 'light', toggleTheme: vi.fn() }) }));
vi.mock('@shared/contexts/SystemSettingsContext', () => ({ SystemSettingsProvider: ({ children }: { children: React.ReactNode }) => children, useSystemSettings: () => ({ settings: { name: 'Erada Platform', name_en: 'Erada' } }) }));
vi.mock('@shared/ui/Toast', () => ({ ToastProvider: ({ children }: { children: React.ReactNode }) => children }));
vi.mock('@shared/api/client', () => ({ api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn(), blob: vi.fn() } }));

const apiGet = vi.mocked(api.get);
const apiPost = vi.mocked(api.post);
const apiPut = vi.mocked(api.put);
const apiDelete = vi.mocked(api.delete);
const incidentType = { id: 'type-1', name: 'medication', name_ar: 'دوائي', is_active: true, requires_reportable_type: false, reportable_types: [] };
function setPath(path: string) { window.history.replaceState({ usr: null, key: 'incident-types-test', idx: 0 }, '', path); }

describe('admin incident-type contracts', () => {
  beforeEach(() => { vi.clearAllMocks(); document.documentElement.dir = 'rtl'; });

  it('uses canonical incident-type and governance adapters', async () => {
    apiGet.mockResolvedValue({ data: [] });
    apiPost.mockResolvedValue({ data: incidentType });
    apiPut.mockResolvedValue({ data: incidentType });
    apiDelete.mockResolvedValue({ message: 'deleted' });
    await adminApi.incidentTypes.list();
    await adminApi.incidentTypes.create({ name: 'medication', name_ar: 'دوائي', is_active: true });
    await adminApi.incidentTypes.update('type-1', { name_ar: 'دوائي محدث' });
    await adminApi.incidentTypes.addReportableType('type-1', { name: 'dose', name_ar: 'جرعة' });
    await adminApi.incidentTypes.delete('type-1');
    expect(apiGet).toHaveBeenCalledWith('/admin/incident-types');
    expect(apiPost).toHaveBeenNthCalledWith(1, '/admin/incident-types', { name: 'medication', name_ar: 'دوائي', is_active: true });
    expect(apiPut).toHaveBeenCalledWith('/admin/incident-types/type-1', { name_ar: 'دوائي محدث' });
    expect(apiPost).toHaveBeenNthCalledWith(2, '/admin/incident-types/type-1/reportable-types', { name: 'dose', name_ar: 'جرعة' });
    expect(apiDelete).toHaveBeenCalledWith('/admin/incident-types/type-1');
  });

  it('validates, creates, edits, and confirms category deletion', async () => {
    apiGet
      .mockResolvedValueOnce({ data: [incidentType] })
      .mockResolvedValueOnce({ data: [{ resource_type: 'ovr', label: 'Incidents', governing_unit_id: null, governing_unit_name: null, applies_to_children: true }] })
      .mockResolvedValueOnce({ data: [{ id: 4, name: 'Quality' }] });
    apiPost.mockResolvedValue({ data: incidentType });
    apiPut.mockResolvedValue({ data: incidentType });
    apiDelete.mockResolvedValue({ message: 'deleted' });
    vi.spyOn(window, 'confirm').mockReturnValue(true);
    const actor = userEvent.setup();
    setPath('/incident-types');
    render(<AdminRouter />);

    const createButton = await screen.findByRole('button', { name: i18n.t('ovr.add_category') });
    await actor.click(createButton);
    await actor.click(screen.getByRole('button', { name: i18n.t('common.save') }));
    expect(apiPost).not.toHaveBeenCalled();
    await actor.type(screen.getByLabelText(i18n.t('ovr.category_name')), 'medication');
    await actor.type(screen.getByLabelText(i18n.t('ovr.category_name_ar')), 'دوائي');
    await actor.click(screen.getByRole('button', { name: i18n.t('common.save') }));
    await waitFor(() => expect(apiPost).toHaveBeenCalledWith('/admin/incident-types', { name: 'medication', name_ar: 'دوائي', is_active: true }));

    const row = screen.getByRole('row', { name: /medication/ });
    await actor.click(within(row).getByRole('button', { name: i18n.t('common.delete') }));
    expect(apiDelete).toHaveBeenCalledWith('/admin/incident-types/type-1');
  });

  it('selects the OVR governing department and reports mutation failures', async () => {
    apiGet
      .mockResolvedValueOnce({ data: [incidentType] })
      .mockResolvedValueOnce({ data: [{ resource_type: 'ovr', label: 'Incidents', governing_unit_id: null, governing_unit_name: null, applies_to_children: true }] })
      .mockResolvedValueOnce({ data: [{ id: 4, name: 'Quality' }] });
    apiPut.mockRejectedValue({ message: 'Department belongs to another organization' });
    const actor = userEvent.setup();
    setPath('/incident-types');
    render(<AdminRouter />);
    const select = await screen.findByLabelText(i18n.t('ovr.governing_department'));
    await actor.selectOptions(select, '4');
    expect(apiPut).toHaveBeenCalledWith('/admin/governance-rules', { resource_type: 'ovr', governing_unit_id: 4 });
    expect(await screen.findByRole('alert')).toHaveTextContent('Department belongs to another organization');
    expect(select).toHaveValue('');
  });
});
