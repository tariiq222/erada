import React from 'react';
import { describe, expect, it, vi, beforeEach } from 'vitest';
import { render, waitFor } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';

/**
 * Task 13 (OrgAdmin plan): SPA — OVR detail route param unification.
 *
 * The authenticated `/ovr/incidents/:reportNumber` route resolves its
 * incident via `incidentsApi.getOne(reportNumber)`. The page must read
 * the `:reportNumber` URL parameter (matching the backend
 * `IncidentReport::getRouteKeyName()` which returns `report_number`) —
 * NOT `:tracking_token`, which is reserved for the public
 * `/ovr/track/:tracking_token` endpoint.
 *
 * This test fails RED on the current implementation because
 * `IncidentView` reads `tracking_token` (always `undefined` for this
 * route) and `incidentsApi.getOne` is therefore invoked with
 * `undefined`. After the fix, the page reads `reportNumber` and the
 * API call must be made with the URL value (e.g. `RPT-001`).
 */

const getOne = vi.fn();
const getComments = vi.fn().mockResolvedValue([]);

vi.mock('@entities/incident', () => ({
  incidentsApi: {
    getOne: (...args: unknown[]) => getOne(...args),
    getComments: (...args: unknown[]) => getComments(...args),
  },
}));

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string) => key,
  }),
}));

vi.mock('@shared/lib/utils', () => ({
  formatDate: vi.fn((value: string) => `formatted:${value}`),
}));

vi.mock('@shared/ui', () => ({
  Badge: ({ children }: { children: React.ReactNode }) => <span>{children}</span>,
  Breadcrumb: () => null,
  Button: ({ children, onClick }: { children: React.ReactNode; onClick?: () => void }) => (
    <button onClick={onClick}>{children}</button>
  ),
  MaskedField: () => null,
  PageHeader: () => null,
  Skeleton: () => null,
  Tabs: ({ children }: { children: React.ReactNode }) => <>{children}</>,
  TabsContent: ({ children }: { children: React.ReactNode }) => <>{children}</>,
  TabsList: ({ children }: { children: React.ReactNode }) => <>{children}</>,
  TabsTrigger: ({ children }: { children: React.ReactNode }) => <button>{children}</button>,
}));

vi.mock('@shared/ui/IconButton', () => ({
  IconButton: ({ children }: { children: React.ReactNode }) => <button>{children}</button>,
}));

vi.mock('@shared/ui/SkipToMain', () => ({
  SkipToMain: () => null,
}));

vi.mock('@shared/ui/Toast', () => ({
  useToast: () => ({ showToast: vi.fn() }),
}));

vi.mock('@shared/api/access', () => ({
  useCan: () => false,
}));

vi.mock('./components/IncidentViewDetailsTab', () => ({
  default: () => null,
}));
vi.mock('./components/IncidentViewCommentsTab', () => ({
  default: () => null,
}));
vi.mock('./components/IncidentViewAuditTab', () => ({
  default: () => null,
}));

vi.mock('./components/constants', () => ({
  severityColors: { high: 'danger' },
  severityLabels: { high: 'ovr.severity.high' },
  statusColors: { new: 'default' },
  statusLabels: { new: 'ovr.status.new' },
}));

vi.mock('./components/types', () => ({}));

import IncidentView from '@pages/ovr/IncidentView';

const fakeIncident = {
  id: 1,
  report_number: 'RPT-001',
  severity_level: 'high',
  status: 'new',
  is_confidential: false,
  immediate_action_required: false,
  is_patient_related: false,
  status_history: [],
  assigned_to: null,
};

describe('OVR detail route param (Task 13)', () => {
  beforeEach(() => {
    getOne.mockReset();
    getComments.mockClear();
    getOne.mockResolvedValue(fakeIncident);
  });

  it('reads :reportNumber from the URL when fetching the incident', async () => {
    render(
      <MemoryRouter initialEntries={['/ovr/incidents/RPT-001']}>
        <Routes>
          <Route
            path="/ovr/incidents/:reportNumber"
            element={<IncidentView />}
          />
        </Routes>
      </MemoryRouter>,
    );

    await waitFor(() => expect(getOne).toHaveBeenCalledWith('RPT-001'));
    expect(getOne).not.toHaveBeenCalledWith(undefined);
  });
});
