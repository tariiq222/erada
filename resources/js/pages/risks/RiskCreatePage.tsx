import React, { useState, useEffect } from "react";
import { useNavigate } from "react-router-dom";
import {IconTrash, IconPlus, IconShield} from '@tabler/icons-react';
import { Input, Select, Textarea, Button, Card, Badge, DatePicker } from "@shared/ui";
import type { BadgeProps } from "@shared/ui";
import { useToast } from "@shared/ui/Toast";
import { PageHeader, FormSection, FormActions } from "@shared/ui";
import { risksApi } from '@entities/risk';
import { usersApi } from '@entities/user';

const TYPE_OPTIONS = [
  { value: "operational", label: "تشغيلي" },
  { value: "clinical", label: "سريري" },
  { value: "financial", label: "مالي" },
  { value: "technical", label: "تقني" },
  { value: "compliance", label: "امتثال" },
  { value: "reputational", label: "سمعة" },
];

const SCALE = [
  { value: "1", label: "1 – منخفض جداً" },
  { value: "2", label: "2 – منخفض" },
  { value: "3", label: "3 – متوسط" },
  { value: "4", label: "4 – مرتفع" },
  { value: "5", label: "5 – مرتفع جداً" },
];

type PriorityLevel = "منخفض" | "متوسط" | "مرتفع" | "حرج";

function calcPriority(likelihood: number, impact: number): { score: number; level: PriorityLevel } {
  const score = likelihood * impact;
  let level: PriorityLevel;
  if (score <= 3) level = "منخفض";
  else if (score <= 6) level = "متوسط";
  else if (score <= 12) level = "مرتفع";
  else level = "حرج";
  return { score, level };
}

// متوسط (accent) ومرتفع (warning) يجب أن يتمايزا لونياً – لا نستخدم نفس النغمة
const PRIORITY_VARIANT: Record<PriorityLevel, BadgeProps["variant"]> = {
  "منخفض": "success",
  "متوسط": "accent",
  "مرتفع": "warning",
  "حرج": "danger",
};

interface ActionRow {
  title: string;
  owner_id: string;
  due_date: string;
}

const emptyAction = (): ActionRow => ({ title: "", owner_id: "", due_date: "" });

interface SelectOption {
  value: string;
  label: string;
}

const RiskCreatePage: React.FC = () => {
  const navigate = useNavigate();
  const { showToast } = useToast();
  const [isSaving, setIsSaving] = useState(false);

  const [departments, setDepartments] = useState<SelectOption[]>([]);
  const [users, setUsers] = useState<SelectOption[]>([]);

  const [form, setForm] = useState({
    discovery_date: new Date().toISOString().slice(0, 10),
    type: "operational",
    department_id: "",
    owner_id: "",
    title: "",
    description: "",
    consequences: "",
    initial_likelihood: "2",
    initial_impact: "2",
  });

  const [actions, setActions] = useState<ActionRow[]>([emptyAction()]);

  useEffect(() => {
    const load = async () => {
      try {
        const [deptRes, userRes]: [any, any] = await Promise.all([
          risksApi.getCreatableDepartments(),
          usersApi.getList(),
        ]);
        const deptList = Array.isArray(deptRes.data?.all)
          ? deptRes.data.all
          : (Array.isArray(deptRes.data) ? deptRes.data : (deptRes.data?.data ?? []));
        const userList = Array.isArray(userRes.data) ? userRes.data : (userRes.data?.data ?? []);
        setDepartments(deptList.map((d: any) => ({ value: String(d.id), label: d.name })));
        setUsers(userList.map((u: any) => ({ value: String(u.id), label: u.name })));
      } catch {
        // silently ignore – selects will remain empty
      }
    };
    load();
  }, []);

  const priority = calcPriority(Number(form.initial_likelihood), Number(form.initial_impact));

  const setField = (key: keyof typeof form) => (e: { target: { value: string } }) =>
    setForm((prev) => ({ ...prev, [key]: e.target.value }));

  const updateAction = (index: number, key: keyof ActionRow, value: string) => {
    setActions((prev) => prev.map((a, i) => (i === index ? { ...a, [key]: value } : a)));
  };

  const addAction = () => setActions((prev) => [...prev, emptyAction()]);

  const removeAction = (index: number) => {
    setActions((prev) => {
      if (prev.length <= 1) return prev;
      return prev.filter((_, i) => i !== index);
    });
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setIsSaving(true);
    try {
      const payload = {
        discovery_date: form.discovery_date,
        type: form.type,
        department_id: form.department_id ? Number(form.department_id) : null,
        owner_id: form.owner_id ? Number(form.owner_id) : null,
        title: form.title,
        description: form.description,
        consequences: form.consequences,
        initial_likelihood: Number(form.initial_likelihood),
        initial_impact: Number(form.initial_impact),
        response_type: "mitigate",
        actions: actions
          .filter((a) => a.title.trim())
          .map((a) => ({
            title: a.title.trim(),
            owner_id: a.owner_id ? Number(a.owner_id) : null,
            due_date: a.due_date || null,
          })),
      };
      const res: any = await risksApi.create(payload);
      showToast("success", "تم تسجيل الخطر بنجاح");
      navigate(`/risk-management/risks/${res.data.id}`);
    } catch (err: any) {
      showToast("error", err?.response?.data?.message ?? "فشل الحفظ");
    } finally {
      setIsSaving(false);
    }
  };

  return (
    <Card>
      <PageHeader
        title="تسجيل خطر جديد"
        icon={IconShield}
        iconTone="risk"
      />
      <form onSubmit={handleSubmit} className="space-y-6 mt-6">
        <FormSection title="معلومات الخطر الأساسية" columns={2}>
          <div className="sm:col-span-2 grid grid-cols-1 gap-x-5 gap-y-5 md:grid-cols-2 xl:grid-cols-4">
            <DatePicker
              label="تاريخ كشف الخطر"
              value={form.discovery_date}
              onChange={(value) => setForm((prev) => ({ ...prev, discovery_date: value }))}
            />
            <Select
              label="نوع الخطر"
              options={TYPE_OPTIONS}
              value={form.type}
              onChange={setField("type")}
            />
            <Select
              label="موقع الخطر (القسم)"
              options={departments}
              value={form.department_id}
              onChange={setField("department_id")}
              placeholder="اختر القسم..."
            />
            <Select
              label="مالك الخطر"
              options={users}
              value={form.owner_id}
              onChange={setField("owner_id")}
              placeholder="اختر المالك..."
            />
          </div>
          <div className="sm:col-span-2">
            <Input
              label="عنوان الخطر"
              required
              value={form.title}
              onChange={setField("title")}
            />
          </div>
          <div className="sm:col-span-2">
            <Textarea
              label="وصف الخطر"
              rows={3}
              value={form.description}
              onChange={setField("description")}
            />
          </div>
          <div className="sm:col-span-2">
            <Textarea
              label="الآثار المتوقعة من الخطر"
              rows={3}
              value={form.consequences}
              onChange={setField("consequences")}
            />
          </div>
        </FormSection>

        <FormSection title="تقييم الخطر" columns={2}>
          <Select
            label="الاحتمالية"
            options={SCALE}
            value={form.initial_likelihood}
            onChange={setField("initial_likelihood")}
          />
          <Select
            label="الأثر"
            options={SCALE}
            value={form.initial_impact}
            onChange={setField("initial_impact")}
          />
          <div className="sm:col-span-2 flex items-center gap-3">
            <span className="text-sm font-medium text-[var(--text-secondary)]">الأولوية:</span>
            <span className="text-lg font-semibold text-[var(--text-primary)]">{priority.score}</span>
            <Badge variant={PRIORITY_VARIANT[priority.level]} size="md">{priority.level}</Badge>
          </div>
        </FormSection>

        <FormSection title="الإجراءات الوقائية">
          <div className="space-y-3">
            {actions.map((action, index) => (
              <div key={index} className="grid grid-cols-1 sm:grid-cols-3 gap-3 items-end">
                <Input
                  label={index === 0 ? "الإجراء" : undefined}
                  placeholder="عنوان الإجراء"
                  value={action.title}
                  onChange={(e) => updateAction(index, "title", e.target.value)}
                />
                <Select
                  label={index === 0 ? "مالك الإجراء" : undefined}
                  options={users}
                  value={action.owner_id}
                  onChange={(e) => updateAction(index, "owner_id", e.target.value)}
                  placeholder="اختر المالك..."
                />
                <div className="flex gap-2 items-end">
                  <div className="flex-1">
                    <DatePicker
                      label={index === 0 ? "مدة التنفيذ" : undefined}
                      value={action.due_date}
                      onChange={(value) => updateAction(index, "due_date", value)}
                    />
                  </div>
                  <Button
                    type="button"
                    variant="danger"
                    size="sm"
                    onClick={() => removeAction(index)}
                    leftIcon={<IconTrash size={14} />}
                    aria-label="حذف الإجراء"
                  >
                    {""}
                  </Button>
                </div>
              </div>
            ))}
            <Button
              type="button"
              variant="outline"
              size="sm"
              onClick={addAction}
              leftIcon={<IconPlus size={14} />}
            >
              إضافة إجراء
            </Button>
          </div>
        </FormSection>

        <FormActions>
          <Button
            type="button"
            variant="secondary"
            onClick={() => navigate("/risk-management/risks")}
          >
            إلغاء
          </Button>
          <Button type="submit" variant="primary" disabled={isSaving}>
            {isSaving ? "جاري الحفظ..." : "حفظ الخطر"}
          </Button>
        </FormActions>
      </form>
    </Card>
  );
};

export default RiskCreatePage;
