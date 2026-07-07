import React from "react";
import { render, screen, fireEvent } from "@testing-library/react";
import { MemoryRouter } from "react-router-dom";
import { describe, it, expect, vi, beforeEach } from "vitest";

// IncidentsTable renders the shared DataTable, which calls useNavigate();
// it must be rendered inside a Router context.
const renderInRouter = (ui: React.ReactElement) =>
	render(<MemoryRouter>{ui}</MemoryRouter>);

vi.mock("react-i18next", () => ({
	useTranslation: () => ({
		t: (key: string) =>
			(
				({
					"ovr.no_incidents": "لا توجد حوادث مسجلة",
					"ovr.start_reporting": "ابدأ بتسجيل أول حادثة",
					"ovr.report_new_incident": "تسجيل حادثة جديدة",
					"ovr.report_number": "رقم البلاغ",
					"ovr.incident_type": "نوع الحادثة",
					"ovr.date": "التاريخ",
					"ovr.severity": "الخطورة",
					"ovr.reporter": "المبلّغ",
					"ovr.severity.high": "عالية",
					"ovr.severity.critical": "حرجة",
					"ovr.status.new": "جديد",
					"ovr.status.resolved": "تم الحل",
					"common.status": "الحالة",
					"common.actions": "الإجراءات",
					"common.view_details": "عرض التفاصيل",
					"common.edit": "تعديل",
					"common.page": "صفحة",
					"common.next": "التالي",
					"common.previous": "السابق",
					"common.total": "الإجمالي",
				}) as Record<string, string>
			)[key] ?? key,
	}),
}));

vi.mock("@shared/lib/utils", async (importOriginal) => {
	const actual = await importOriginal<typeof import("@shared/lib/utils")>();
	return {
		...actual,
		formatDate: vi.fn((date) => `Formatted: ${date}`),
	};
});

vi.mock('@tabler/icons-react', async () => {
  const actual = await vi.importActual<typeof import('@tabler/icons-react')>('@tabler/icons-react');
  return {
    ...actual,


	IconAlertTriangle: () => <span data-testid="alert-icon" />,
	IconPlus: () => <span data-testid="plus-icon" />,
	IconEdit: () => <span data-testid="edit-icon" />,
	IconEye: () => <span data-testid="eye-icon" />,
	IconClock: () => <span data-testid="clock-icon" />,
	IconUser: () => <span data-testid="user-icon" />,

  };
});

// IncidentsTable now renders the real shared DataTable (with RowAction,
// StatusBadge, Button and the real Pagination). We render against the real
// @shared/ui so assertions reflect the current table structure.

import IncidentsTable from "@pages/ovr/components/IncidentsTable";

const incidents = [
	{
		id: 1,
		report_number: "OVR-001",
		description: "وصف بلاغ أول",
		incident_type: { id: 1, name: "خطأ دوائي" },
		incident_datetime: "2026-06-09 10:00:00",
		severity_level: "high",
		status: "new",
		reporter: { id: 1, name: "محمد" },
	},
	{
		id: 2,
		report_number: "OVR-002",
		description: "بلاغ حرج",
		incident_type: null,
		created_at: "2026-06-08 09:00:00",
		severity_level: "critical",
		status: "resolved",
		reporter: { id: 2, name: "أحمد" },
	},
];

const pagination = { currentPage: 1, lastPage: 2, perPage: 10, total: 20 };

const baseProps = {
	incidents,
	isLoading: false,
	pagination,
	canCreate: true,
	canEditAll: true,
	canEditOwn: false,
	currentUserId: 1,
	onPageChange: vi.fn(),
	onView: vi.fn(),
	onEdit: vi.fn(),
	onAddNew: vi.fn(),
};

describe("IncidentsTable", () => {
	beforeEach(() => vi.clearAllMocks());

	it("renders loading skeletons", () => {
		renderInRouter(<IncidentsTable {...baseProps} incidents={[]} isLoading />);
		// The shared DataTable renders animate-pulse skeleton cells while loading.
		expect(
			document.querySelectorAll(".animate-pulse").length,
		).toBeGreaterThan(0);
	});

	it("renders empty state and add action when allowed", () => {
		renderInRouter(<IncidentsTable {...baseProps} incidents={[]} />);
		expect(screen.getByText("لا توجد حوادث مسجلة")).toBeInTheDocument();
		fireEvent.click(screen.getByText("تسجيل حادثة جديدة"));
		expect(baseProps.onAddNew).toHaveBeenCalled();
	});

	it("renders current table columns and incident rows", () => {
		renderInRouter(<IncidentsTable {...baseProps} />);
		expect(screen.getByText("رقم البلاغ")).toBeInTheDocument();
		expect(screen.getByText("نوع الحادثة")).toBeInTheDocument();
		expect(screen.getByText("OVR-001")).toBeInTheDocument();
		expect(screen.getByText("وصف بلاغ أول")).toBeInTheDocument();
		expect(screen.getByText("خطأ دوائي")).toBeInTheDocument();
		expect(
			screen.getByText("Formatted: 2026-06-09 10:00:00"),
		).toBeInTheDocument();
		expect(screen.getByText("محمد")).toBeInTheDocument();
	});

	it("calls view and edit handlers", () => {
		renderInRouter(<IncidentsTable {...baseProps} />);
		fireEvent.click(screen.getAllByTitle("عرض التفاصيل")[0]);
		expect(baseProps.onView).toHaveBeenCalledWith(incidents[0]);
		fireEvent.click(screen.getAllByTitle("تعديل")[0]);
		expect(baseProps.onEdit).toHaveBeenCalledWith(incidents[0]);
	});

	it("shows pagination and handles page changes", () => {
		renderInRouter(<IncidentsTable {...baseProps} />);
		// The shared Pagination renders a page-2 button (lastPage = 2).
		fireEvent.click(screen.getByRole("button", { name: /صفحة 2/ }));
		expect(baseProps.onPageChange).toHaveBeenCalledWith(2);
	});

	it("hides edit button when permissions do not allow editing", () => {
		renderInRouter(
			<IncidentsTable {...baseProps} canEditAll={false} canEditOwn={false} />,
		);
		expect(screen.queryByTitle("تعديل")).not.toBeInTheDocument();
	});
});
