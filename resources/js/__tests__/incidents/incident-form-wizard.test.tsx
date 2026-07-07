import React from "react";
import { render, screen, fireEvent } from "@testing-library/react";
import { describe, it, expect, vi, beforeEach } from "vitest";

vi.mock("react-i18next", () => {
	const translations: Record<string, string> = {
		"ovr.new_incident": "حادثة جديدة",
		"ovr.edit_incident": "تعديل الحادثة",
		"ovr.step_incident_type": "نوع الحادثة",
		"ovr.step_details": "تفاصيل الحادثة",
		"ovr.step_patient": "بيانات المريض",
		"ovr.step_actions": "الإجراءات",
		"ovr.review_submit": "مراجعة وإرسال",
		"ovr.step_x_of_y": "الخطوة {{current}} من {{total}}",
		"ovr.field_required": "هذا الحقل مطلوب: {{field}}",
		"ovr.incident_type": "نوع الحادثة",
		"ovr.select_incident_type": "اختر نوع الحادثة",
		"ovr.sub_type": "النوع الفرعي",
		"ovr.select_reportable_type": "اختر نوع الجهة المبلّغ عنها",
		"ovr.reporter": "المبلّغ",
		"ovr.reporter_autofill_hint": "تُعبأ تلقائياً من حسابك",
		"ovr.severity": "الخطورة",
		"ovr.severity_low": "منخفض",
		"ovr.severity_medium": "متوسط",
		"ovr.severity_high": "عالي",
		"ovr.severity_critical": "حرج",
		"ovr.sla_low": "المعالجة خلال 48 ساعة",
		"ovr.sla_medium": "المعالجة خلال 48 ساعة",
		"ovr.sla_high": "المعالجة خلال 24 ساعة",
		"ovr.sla_critical": "المعالجة خلال 4 ساعات",
		"ovr.incident_date": "تاريخ الحادثة",
		"ovr.incident_time": "وقت الحادثة",
		"ovr.select_incident_date": "اختر تاريخ الحادثة",
		"ovr.no_future_datetime": "لا يمكن اختيار تاريخ مستقبلي",
		"ovr.incident_description": "وصف الحادثة",
		"ovr.description_placeholder": "أدخل وصفاً تفصيلياً للحادثة",
		"ovr.is_patient_related": "متعلق بمريض",
		"ovr.patient_name": "اسم المريض",
		"ovr.patient_file_number": "رقم الملف",
		"ovr.patient_not_related_hint": "هذه الحادثة غير متعلقة بمريض.",
		"ovr.actions_taken": "الإجراءات المتخذة",
		"ovr.actions_taken_placeholder": "صف الإجراءات",
		"ovr.immediate_action_required": "إجراء فوري مطلوب",
		"ovr.informed_authority": "تم إبلاغ الجهة المختصة",
		"ovr.contributing_factors": "العوامل المساهمة",
		"ovr.factor_communication": "التواصل",
		"ovr.factor_staffing": "التوظيف",
		"ovr.factor_training": "التدريب",
		"ovr.factor_environment": "البيئة",
		"ovr.factor_equipment": "المعدات",
		"ovr.factor_policies": "السياسات والإجراءات",
		"ovr.factor_patient_factors": "عوامل المريض",
		"ovr.factor_teamwork": "العمل الجماعي",
		"ovr.factor_leadership": "القيادة",
		"ovr.factor_other": "أخرى",
		"ovr.is_confidential": "سرية",
		"ovr.register": "تسجيل",
		"common.name": "الاسم",
		"common.department": "القسم",
		"common.optional": "اختياري",
		"common.cancel": "إلغاء",
		"common.back": "رجوع",
		"common.next": "التالي",
		"common.update": "تحديث",
		"common.yes": "نعم",
		"common.no": "لا",
		"users.job_title": "المسمى الوظيفي",
		"users.extension": "التحويلة",
	};

	return {
		useTranslation: () => ({
			t: (key: string, opts?: Record<string, unknown>) => {
				let value = translations[key] ?? key;
				if (opts) {
					for (const [k, v] of Object.entries(opts)) {
						value = value.replace(`{{${k}}}`, String(v));
					}
				}
				return value;
			},
		}),
	};
});

vi.mock('@entities/incident', () => ({
  incidentsApi: {
		create: vi.fn().mockResolvedValue({}),
		update: vi.fn().mockResolvedValue({}),
	},
}));

vi.mock("@shared/ui/Toast", () => ({
	useToast: () => ({ showToast: vi.fn() }),
}));

vi.mock("@shared/contexts/AuthContext", () => ({
	useAuth: () => ({
		user: {
			id: 7,
			name: "أحمد المبلّغ",
			email: "ahmad@example.com",
			job_title: "فني مختبر",
			extension: "4321",
			department_id: 3,
			department: { id: 3, name: "المختبر" },
			roles: [],
		},
	}),
}));

vi.mock("@shared/ui", () => ({
	Button: ({ children, onClick, type, disabled }: any) => (
		<button type={type || "button"} onClick={onClick} disabled={disabled}>
			{children}
		</button>
	),
	Modal: ({ isOpen, title, children }: any) =>
		isOpen ? (
			<div data-testid="modal">
				<h2>{title}</h2>
				{children}
			</div>
		) : null,
	Select: ({ label, value, onChange, options, required, disabled, placeholder }: any) => (
		<div>
			<label htmlFor={`select-${label}`}>{label}</label>
			<select
				id={`select-${label}`}
				aria-label={label}
				data-required={required ? "true" : "false"}
				data-placeholder={placeholder}
				disabled={disabled}
				value={value}
				onChange={(e) => onChange({ target: { value: e.target.value } })}
			>
				{options.map((opt: any) => (
					<option key={opt.value} value={opt.value}>
						{opt.label}
					</option>
				))}
			</select>
		</div>
	),
	DatePicker: ({ label, value, onChange, maxDate, hint }: any) => (
		<div>
			<label>{label}</label>
			<input
				data-testid="incident-date"
				type="date"
				max={maxDate}
				value={value}
				onChange={(e) => onChange(e.target.value)}
			/>
			{hint && <p>{hint}</p>}
		</div>
	),
	Input: ({ label, hint, ...props }: any) => (
		<div>
			<label>{label}</label>
			<input aria-label={label} {...props} />
			{hint && <p>{hint}</p>}
		</div>
	),
	Textarea: ({ label, ...props }: any) => (
		<div>
			<label>{label}</label>
			<textarea aria-label={label} {...props} />
		</div>
	),
	Switch: ({ label, checked, onChange }: any) => (
		<label>
			<input type="checkbox" role="switch" checked={checked} onChange={onChange} />
			{label}
		</label>
	),
	Checkbox: ({ label, checked, onChange }: any) => (
		<label>
			<input type="checkbox" aria-label={label} checked={checked} onChange={onChange} />
			{label}
		</label>
	),
	Progress: () => <div data-testid="progress" />,
	Badge: ({ children }: any) => <span>{children}</span>,
}));

import IncidentFormWizard from "@pages/ovr/components/IncidentFormWizard";
import type { Category } from "@pages/ovr/components/types";

const categories: Category[] = [
	{
		id: 1,
		name: "سقوط",
		severity_level: "medium",
		requires_reportable_type: true,
		reportableTypes: [{ id: 11, name: "سقوط من السرير" }],
	},
	{
		id: 2,
		name: "حادثة عامة",
		severity_level: "low",
		reportableTypes: [{ id: 21, name: "نوع فرعي عام" }],
	},
];

const localToday = (): string => {
	const d = new Date();
	const month = String(d.getMonth() + 1).padStart(2, "0");
	const day = String(d.getDate()).padStart(2, "0");
	return `${d.getFullYear()}-${month}-${day}`;
};

const renderWizard = () =>
	render(
		<IncidentFormWizard
			isOpen={true}
			incident={null}
			categories={categories}
			onClose={vi.fn()}
			onSuccess={vi.fn()}
		/>,
	);

const selectCategory = (id: number) => {
	fireEvent.change(screen.getByLabelText("نوع الحادثة"), {
		target: { value: id.toString() },
	});
};

const clickNext = () => {
	fireEvent.click(screen.getByRole("button", { name: "التالي" }));
};

describe("IncidentFormWizard", () => {
	beforeEach(() => {
		vi.clearAllMocks();
	});

	it("renders a read-only reporter card auto-filled from the auth context", () => {
		renderWizard();

		const card = screen.getByTestId("reporter-card");
		expect(card).toHaveTextContent("المبلّغ");
		expect(card).toHaveTextContent("أحمد المبلّغ");
		expect(card).toHaveTextContent("فني مختبر");
		expect(card).toHaveTextContent("المختبر");
		expect(card).toHaveTextContent("4321");
		expect(card).toHaveTextContent("تُعبأ تلقائياً من حسابك");

		// لا توجد حقول إدخال داخل بطاقة المبلّغ (قراءة فقط)
		expect(card.querySelector("input")).toBeNull();
		expect(card.querySelector("select")).toBeNull();
	});

	it("lists only the selected category's reportable types in the sub-type dropdown", () => {
		renderWizard();
		selectCategory(1);

		const subTypeSelect = screen.getByLabelText("النوع الفرعي") as HTMLSelectElement;
		const optionLabels = Array.from(subTypeSelect.options).map((o) => o.textContent);
		expect(optionLabels).toContain("سقوط من السرير");
		expect(optionLabels).not.toContain("نوع فرعي عام");
	});

	it("requires the sub-type when the category has requires_reportable_type", () => {
		renderWizard();
		selectCategory(1);

		expect(screen.getByLabelText("النوع الفرعي")).toHaveAttribute(
			"data-required",
			"true",
		);

		clickNext();

		// يبقى في الخطوة الأولى مع رسالة خطأ
		expect(screen.getByText("هذا الحقل مطلوب: النوع الفرعي")).toBeInTheDocument();
		expect(screen.queryByTestId("incident-date")).not.toBeInTheDocument();

		// بعد اختيار النوع الفرعي ينتقل للخطوة الثانية
		fireEvent.change(screen.getByLabelText("النوع الفرعي"), {
			target: { value: "11" },
		});
		clickNext();
		expect(screen.getByTestId("incident-date")).toBeInTheDocument();
	});

	it("treats the sub-type as optional when the category does not require it", () => {
		renderWizard();
		selectCategory(2);

		const subTypeSelect = screen.getByLabelText("النوع الفرعي");
		expect(subTypeSelect).toHaveAttribute("data-required", "false");
		expect(subTypeSelect).toHaveAttribute("data-placeholder", "اختياري");

		clickNext();

		expect(
			screen.queryByText("هذا الحقل مطلوب: النوع الفرعي"),
		).not.toBeInTheDocument();
		expect(screen.getByTestId("incident-date")).toBeInTheDocument();
	});

	it("blocks future dates: max is set to today with a helper text", () => {
		renderWizard();
		selectCategory(2);
		clickNext();

		const dateInput = screen.getByTestId("incident-date");
		expect(dateInput).toHaveAttribute("max", localToday());
		expect(screen.getByText("لا يمكن اختيار تاريخ مستقبلي")).toBeInTheDocument();
	});

	it("shows the SLA hint for every severity option", () => {
		renderWizard();
		selectCategory(2);
		clickNext();

		const severitySelect = screen.getByLabelText("الخطورة") as HTMLSelectElement;
		const optionLabels = Array.from(severitySelect.options).map((o) => o.textContent);
		expect(optionLabels).toContain("حرج - المعالجة خلال 4 ساعات");
		expect(optionLabels).toContain("عالي - المعالجة خلال 24 ساعة");
		expect(optionLabels).toContain("متوسط - المعالجة خلال 48 ساعة");
		expect(optionLabels).toContain("منخفض - المعالجة خلال 48 ساعة");
	});

	it("renders the 10 contributing factor checkboxes on the actions step", () => {
		renderWizard();
		selectCategory(2);
		clickNext(); // الخطوة 2: التفاصيل (التاريخ الافتراضي اليوم)
		clickNext(); // الخطوة 3: بيانات المريض (غير متعلق بمريض)
		clickNext(); // الخطوة 4: الإجراءات

		const factorLabels = [
			"التواصل",
			"التوظيف",
			"التدريب",
			"البيئة",
			"المعدات",
			"السياسات والإجراءات",
			"عوامل المريض",
			"العمل الجماعي",
			"القيادة",
			"أخرى",
		];
		for (const label of factorLabels) {
			expect(screen.getByLabelText(label)).toBeInTheDocument();
		}

		// تحديد عامل مساهم يعمل
		const communication = screen.getByLabelText("التواصل") as HTMLInputElement;
		expect(communication.checked).toBe(false);
		fireEvent.click(communication);
		expect((screen.getByLabelText("التواصل") as HTMLInputElement).checked).toBe(true);
	});
});
