import React from "react";
import { act, render, screen, fireEvent, waitFor } from "@testing-library/react";
import { describe, it, expect, vi, beforeEach } from "vitest";

vi.mock("react-i18next", () => ({
	useTranslation: () => ({
		t: (key: string) =>
			(
				({
					"ovr.report": "بلاغ",
					"ovr.details": "التفاصيل",
					"ovr.status_history": "سجل الحالة",
					"ovr.comments": "التعليقات",
					"ovr.audit_trail": "سجل التدقيق",
					"ovr.incident_type": "نوع الحادثة",
					"ovr.reportable_type": "نوع البلاغ",
					"ovr.incident_datetime": "وقت الحادثة",
					"ovr.reporter": "المبلّغ",
					"ovr.patient_info": "بيانات المريض",
					"ovr.patient_data": "بيانات المريض",
					"ovr.patient_name": "اسم المريض",
					"ovr.patient_file_number": "رقم ملف المريض",
					"ovr.description": "الوصف",
					"ovr.actions_taken": "الإجراءات المتخذة",
					"ovr.contributing_factors": "العوامل المساهمة",
					"ovr.no_comments": "لا توجد تعليقات",
					"common.close": "إغلاق",
					"ovr.severity.high": "عالية",
					"ovr.status.new": "جديد",
				}) as Record<string, string>
			)[key] ?? key,
	}),
}));

vi.mock("@shared/lib/utils", () => ({
	formatDate: vi.fn((date) => `Formatted: ${date}`),
}));

vi.mock('@entities/incident', () => ({
  incidentsApi: {
		getComments: vi
			.fn()
			.mockResolvedValue({
				data: [
					{
						id: 1,
						content: "تعليق تجريبي",
						user: { name: "مراجع" },
						created_at: "2026-01-01",
					},
				],
			}),
	},
}));

vi.mock("@shared/ui", () => ({
	Button: ({ children, onClick }: any) => (
		<button onClick={onClick}>{children}</button>
	),
	Badge: ({ children, variant }: any) => (
		<span data-testid="badge" data-variant={variant}>
			{children}
		</span>
	),
	Modal: ({ isOpen, onClose, title, children }: any) =>
		isOpen ? (
			<div data-testid="modal">
				<h2 data-testid="modal-title">{title}</h2>
				<button data-testid="modal-x" onClick={onClose}>
					X
				</button>
				{children}
			</div>
		) : null,
	Tabs: ({ children }: any) => <div>{children}</div>,
	TabsList: ({ children }: any) => <div>{children}</div>,
	TabsTrigger: ({ children, value, onClick }: any) => (
		<button data-testid={`tab-${value}`} onClick={onClick}>
			{children}
		</button>
	),
	TabsContent: ({ children, value }: any) => (
		<div data-testid={`tab-content-${value}`}>{children}</div>
	),
	Skeleton: ({ className }: any) => (
		<div data-testid="skeleton" className={className} />
	),
}));

vi.mock("@pages/ovr/components/AuditLogTab", () => ({
	default: ({ reportId }: { reportId: string }) => (
		<div data-testid="audit-tab">Audit {reportId}</div>
	),
}));

import IncidentViewModal from "@pages/ovr/components/IncidentViewModal";
import { incidentsApi } from '@entities/incident';

const mockIncident = {
	id: 1,
	report_number: "OVR-001",
	severity_level: "high",
	status: "new",
	is_confidential: false,
	immediate_action_required: false,
	incident_type: { id: 1, name: "خطأ دوائي" },
	reportable_incident_type: { id: 2, name: "دواء" },
	incident_datetime: "2026-06-09 10:30:00",
	reporter: { id: 1, name: "محمد أحمد" },
	is_patient_related: true,
	patient_name: "مريض تجريبي",
	patient_file_number: "PF-100",
	description: "وصف الحادثة التجريبية",
	actions_taken: "تم اتخاذ إجراء أولي",
	contributing_factors: ["process", "training"],
	status_history: [],
};

describe("IncidentViewModal", () => {
	beforeEach(() => {
		vi.clearAllMocks();
	});

	it("returns null when closed or incident is missing", () => {
		const { container, rerender } = render(
			<IncidentViewModal
				isOpen={false}
				incident={mockIncident as any}
				onClose={vi.fn()}
			/>,
		);
		expect(container.firstChild).toBeNull();

		rerender(
			<IncidentViewModal isOpen={true} incident={null} onClose={vi.fn()} />,
		);
		expect(container.firstChild).toBeNull();
	});

	it("renders current incident details", async () => {
		render(
			<IncidentViewModal
				isOpen
				incident={mockIncident as any}
				onClose={vi.fn()}
			/>,
		);

		expect(screen.getByTestId("modal-title")).toHaveTextContent("بلاغ OVR-001");
		expect(screen.getByText("خطأ دوائي")).toBeInTheDocument();
		expect(screen.getByText("دواء")).toBeInTheDocument();
		expect(
			screen.getByText("Formatted: 2026-06-09 10:30:00"),
		).toBeInTheDocument();
		expect(screen.getByText("محمد أحمد")).toBeInTheDocument();
		expect(screen.getByText("مريض تجريبي")).toBeInTheDocument();
		expect(screen.getByText("PF-100")).toBeInTheDocument();
		expect(screen.getByText("وصف الحادثة التجريبية")).toBeInTheDocument();
		expect(screen.getByText("تم اتخاذ إجراء أولي")).toBeInTheDocument();

		await waitFor(() =>
			expect(incidentsApi.getComments).toHaveBeenCalledWith("OVR-001"),
		);
	});

	it("calls onClose from close actions", async () => {
		const onClose = vi.fn();
		render(
			<IncidentViewModal
				isOpen
				incident={mockIncident as any}
				onClose={onClose}
			/>,
		);

		await act(async () => { await Promise.resolve(); });
		fireEvent.click(screen.getByTestId("modal-x"));
		expect(onClose).toHaveBeenCalledTimes(1);
	});

	it("renders audit tab with report number", async () => {
		render(
			<IncidentViewModal
				isOpen
				incident={mockIncident as any}
				onClose={vi.fn()}
			/>,
		);
		await act(async () => { await Promise.resolve(); });
		expect(screen.getByTestId("audit-tab")).toHaveTextContent("OVR-001");
	});
});
