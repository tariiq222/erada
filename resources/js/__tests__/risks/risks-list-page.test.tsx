import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import React from "react";
import { MemoryRouter } from "react-router-dom";

// Minimal i18n stub
vi.mock("react-i18next", () => ({
  useTranslation: () => ({ t: (k: string) => k }),
}));

// Auth stub: admin can view + create + view_reports.
// Phase 9.3: production code reads useCan('risks.*') from `user.access`.
// Surface the canonical dotted form to mirror the live /api/auth/me payload.
vi.mock("@shared/contexts/AuthContext", () => ({
  useAuth: () => ({
    can: (capability: string) => ["risks.view", "risks.create", "risks.view_reports"].includes(capability),
    user: {
      id: 1,
      access: {
        "risks.view": true,
        "risks.create": true,
        "risks.view_reports": true,
      },
    },
  }),
}));

// Toast stub
vi.mock("@shared/ui/Toast", () => ({
  useToast: () => ({ showToast: vi.fn() }),
}));

// StatusBadge stub: render the human label when supplied (custom badges pass label),
// and expose the raw status value via data-status so individual badges can be targeted.
vi.mock("@shared/ui/StatusBadge", () => ({
  StatusBadge: ({ status, label }: { status: string; label?: string }) => (
    <span data-testid="status" data-status={status}>{label ?? status}</span>
  ),
}));

// API stub
const listMock = vi.fn();
vi.mock('@entities/risk', () => ({
  risksApi: { list: (...args: any[]) => listMock(...args) },
  risksDashboardApi: {
    get: vi.fn(),
    getMatrix: vi.fn(),
    exportUrl: (f: string) => `/api/risk-management/export/${f}`,
  },
}));

import RisksListPage from "@pages/risks/RisksListPage";

describe("RisksListPage", () => {
  beforeEach(() => {
    listMock.mockReset();
    listMock.mockResolvedValue({
      data: [
        {
          id: 1,
          code: "RSK-2026-0001",
          title: "انقطاع التيار",
          status: "open",
          status_label: "مفتوح",
          current_level: "critical",
          current_score: 9,
          type: "operational",
          type_label: "تشغيلي",
          discovery_date: "2026-06-01",
          department: { id: 1, name: "الطوارئ" },
          owner: { id: 1, name: "أحمد" },
          open_actions_count: 2,
        },
      ],
      current_page: 1,
      last_page: 1,
      per_page: 15,
      total: 1,
    });
  });

  it("renders the risk row returned by the API", async () => {
    render(<MemoryRouter><RisksListPage /></MemoryRouter>);
    await waitFor(() => {
      expect(screen.getByText("RSK-2026-0001")).toBeInTheDocument();
    });
    expect(screen.getByText("انقطاع التيار")).toBeInTheDocument();
    // SUT renders the localized level label (LEVEL_LABEL["critical"] === "حرج"), not the raw value.
    // "حرج" also appears as a level-filter option, so assert at least one occurrence (the row badge).
    expect(screen.getAllByText("حرج").length).toBeGreaterThan(0);
    // The status badge renders status_label ("مفتوح"); identify it by its raw status value.
    const statusBadge = screen
      .getAllByTestId("status")
      .find((el) => el.getAttribute("data-status") === "open");
    expect(statusBadge?.textContent).toBe("مفتوح");
  });

  it("shows export buttons for users with view_risk_reports", async () => {
    render(<MemoryRouter><RisksListPage /></MemoryRouter>);
    await waitFor(() => {
      expect(screen.getByText("CSV")).toBeInTheDocument();
      expect(screen.getByText("PDF")).toBeInTheDocument();
    });
  });
});
