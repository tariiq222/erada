import React, { useEffect, useState } from "react";
import { useParams, Link } from "react-router-dom";
import { useTranslation } from "react-i18next";
import {IconAlertTriangle, IconArrowRight, IconInfoCircle} from '@tabler/icons-react';
import { Card, Button, Tabs, TabsList, TabsTrigger, TabsContent, Select, Textarea, Input, DatePicker } from "@shared/ui";
import { useToast } from "@shared/ui/Toast";
import { useCan } from "@shared/api/access";
import { risksApi } from '@entities/risk';
import { StatusBadge } from "@shared/ui/StatusBadge";
import { PageHeader } from "@shared/ui";
import { SectionHeader } from "@shared/ui/SectionHeader";
import { DecisionsSection } from '@features/meetings';
import { IconClipboardCheck } from '@shared/ui/icons';

const LEVEL_COLOR: Record<string, "success" | "info" | "warning" | "danger"> = {
  low: "success", medium: "info", high: "warning", critical: "danger",
};

const LEVEL_LABEL: Record<string, string> = {
  low: "منخفض", medium: "متوسط", high: "عالٍ", critical: "حرج",
};

// Risk status → StatusBadge custom color (open=active danger, treating=in progress, closed=resolved, accepted=neutral)
const STATUS_COLOR: Record<string, "danger" | "warning" | "success" | "secondary"> = {
  open: "danger",
  treating: "warning",
  closed: "success",
  accepted: "secondary",
};

export const RiskDetailPage: React.FC = () => {
  const { id } = useParams<{ id: string }>();
  const canReassess = useCan('risks.reassess');
  const canChangeStatus = useCan('risks.change_status');
  const canViewStrategy = useCan('strategy.view');
  const canCreateStrategy = useCan('strategy.create');
  const canEditStrategy = useCan('strategy.edit');
  const { t } = useTranslation();
  const { showToast } = useToast();
  const [risk, setRisk] = useState<any>(null);
  const [assessForm, setAssessForm] = useState({ likelihood: 2, impact: 2, notes: "", next_review_at: "" });
  const [statusForm, setStatusForm] = useState({ to_status: "treating", reason: "" });

  const load = async () => {
    try {
      const res: any = await risksApi.get(id!);
      setRisk(res.data ?? res);
    } catch (err: any) {
      showToast("error", err?.response?.data?.message ?? "فشل التحميل");
    }
  };
  useEffect(() => { load();   }, [id]);

  const reassess = async () => {
    try {
      await risksApi.reassess(id!, assessForm);
      showToast("success", "تم تسجيل التقييم");
      load();
    } catch (err: any) { showToast("error", err?.response?.data?.message ?? "فشل"); }
  };

  const changeStatus = async () => {
    try {
      await risksApi.changeStatus(id!, statusForm);
      showToast("success", "تم تغيير الحالة");
      load();
    } catch (err: any) { showToast("error", err?.response?.data?.message ?? "فشل"); }
  };

  if (!risk) return <p className="p-6 text-center text-[var(--text-tertiary)]">جاري التحميل...</p>;

  return (
    <div className="space-y-4 sm:space-y-6">
      <PageHeader
        title={risk.title}
        subtitle={risk.code}
        icon={IconAlertTriangle}
        iconTone="risk"
        actions={
          <Link to="/risk-management">
            <Button variant="secondary" size="sm" leftIcon={<IconArrowRight className="h-4 w-4" />}>عودة</Button>
          </Link>
        }
      />
      <div className="flex flex-wrap items-center gap-2">
        <StatusBadge
          type="custom"
          status={risk.current_level}
          label={LEVEL_LABEL[risk.current_level] ?? risk.current_level}
          color={LEVEL_COLOR[risk.current_level] ?? "secondary"}
        />
        <StatusBadge type="custom" status={risk.status} label={risk.status_label ?? risk.status} color={STATUS_COLOR[risk.status] ?? "secondary"} />
        <span className="text-sm text-[var(--text-tertiary)]">الدرجة: {risk.current_score}</span>
      </div>

      <Card>
        <SectionHeader
          title="التفاصيل"
          level={3}
          size="compact"
          icon={IconInfoCircle}
          iconTone="risk"
          className="mb-3"
        />
        <div className="grid grid-cols-2 md:grid-cols-4 gap-3 text-sm">
          <div><span className="text-[var(--text-tertiary)]">النوع: </span>{risk.type_label}</div>
          <div><span className="text-[var(--text-tertiary)]">تاريخ الاكتشاف: </span>{risk.discovery_date}</div>
          <div><span className="text-[var(--text-tertiary)]">القسم: </span>{risk.department?.name ?? "–"}</div>
          <div><span className="text-[var(--text-tertiary)]">المالك: </span>{risk.owner?.name ?? "–"}</div>
          <div><span className="text-[var(--text-tertiary)]">نوع الاستجابة: </span>{risk.response_type_label}</div>
          <div><span className="text-[var(--text-tertiary)]">تاريخ الإغلاق: </span>{risk.target_close_date ?? "–"}</div>
        </div>
        {risk.description && <p className="mt-3 text-sm">{risk.description}</p>}
      </Card>

      <Tabs defaultValue="assessments">
        <TabsList>
          <TabsTrigger value="assessments">التقييمات</TabsTrigger>
          <TabsTrigger value="actions">الإجراءات</TabsTrigger>
          <TabsTrigger value="history">سجل الحالات</TabsTrigger>
          <TabsTrigger value="decisions">
            <span className="inline-flex items-center gap-2">
              <IconClipboardCheck className="h-4 w-4" />
              {t('strategy.decisions.title')}
            </span>
          </TabsTrigger>
        </TabsList>

        <TabsContent value="assessments">
          <Card>
            {canReassess && (
              <div className="grid grid-cols-1 md:grid-cols-5 gap-2 items-end mb-3">
                <Select label="احتمالية" value={String(assessForm.likelihood)} options={[{value:"1",label:"1"},{value:"2",label:"2"},{value:"3",label:"3"},{value:"4",label:"4"},{value:"5",label:"5"}]} onChange={(e) => setAssessForm({ ...assessForm, likelihood: Number(e.target.value) })} />
                <Select label="أثر" value={String(assessForm.impact)} options={[{value:"1",label:"1"},{value:"2",label:"2"},{value:"3",label:"3"},{value:"4",label:"4"},{value:"5",label:"5"}]} onChange={(e) => setAssessForm({ ...assessForm, impact: Number(e.target.value) })} />
                <DatePicker label="موعد المراجعة القادم" value={assessForm.next_review_at} onChange={(value) => setAssessForm({ ...assessForm, next_review_at: value })} />
                <Textarea label="ملاحظات" rows={1} value={assessForm.notes} onChange={(e) => setAssessForm({ ...assessForm, notes: e.target.value })} />
                <Button onClick={reassess}>تسجيل تقييم</Button>
              </div>
            )}
            <ul className="space-y-1 text-sm">
              {(risk.assessments ?? []).map((a: any) => (
                <li key={a.id} className="flex justify-between border-b border-[var(--border-default)] py-1">
                  <span>{a.assessor?.name ?? "–"} · {a.created_at}</span>
                  <span>{a.likelihood}×{a.impact} = {a.score} (<StatusBadge type="custom" status={a.level} label={LEVEL_LABEL[a.level] ?? a.level} color={LEVEL_COLOR[a.level] ?? "secondary"} />)</span>
                </li>
              ))}
            </ul>
          </Card>
        </TabsContent>

        <TabsContent value="actions">
          <Card>
            <p className="text-sm text-[var(--text-tertiary)]">سيتم عرض الإجراءات هنا – مع CRUD بعد إضافة Slice 7 إذا لزم.</p>
            <ul className="space-y-1 text-sm mt-2">
              {(risk.actions ?? []).map((a: any) => (
                <li key={a.id} className="flex justify-between border-b border-[var(--border-default)] py-1">
                  <span>{a.title} · {a.status_label}</span>
                  <span className="text-[var(--text-tertiary)]">استحقاق: {a.due_date ?? "–"}</span>
                </li>
              ))}
            </ul>
          </Card>
        </TabsContent>

        <TabsContent value="history">
          <Card>
            {canChangeStatus && (
              <div className="grid grid-cols-1 md:grid-cols-3 gap-2 items-end mb-3">
                <Select label="الحالة الجديدة" value={statusForm.to_status} options={[
                  { value: "open", label: "مفتوح" },
                  { value: "treating", label: "قيد المعالجة" },
                  { value: "closed", label: "مغلق" },
                  { value: "accepted", label: "مقبول" },
                ]} onChange={(e) => setStatusForm({ ...statusForm, to_status: e.target.value })} />
                <Input label="السبب" value={statusForm.reason} onChange={(e) => setStatusForm({ ...statusForm, reason: e.target.value })} />
                <Button onClick={changeStatus}>تنفيذ التغيير</Button>
              </div>
            )}
            <ul className="space-y-1 text-sm">
              {(risk.status_changes ?? []).map((s: any) => (
                <li key={s.id} className="flex justify-between border-b border-[var(--border-default)] py-1">
                  <span>{s.changer?.name ?? "–"} · {s.created_at}</span>
                  <span>{s.from_status} → {s.to_status} {s.reason && `(${s.reason})`}</span>
                </li>
              ))}
            </ul>
          </Card>
        </TabsContent>

        <TabsContent value="decisions">
          <DecisionsSection
            decidable_type="risk"
            decidable_id={risk.id}
            decidable_name={risk.title}
            permissions={{
              canView: canViewStrategy,
              canCreate: canCreateStrategy,
              canEdit: canEditStrategy,
            }}
          />
        </TabsContent>
      </Tabs>
    </div>
  );
};

export default RiskDetailPage;
