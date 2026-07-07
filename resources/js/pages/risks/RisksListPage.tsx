import React, { useEffect, useState } from "react";
import { useNavigate } from "react-router-dom";
import {
	Button,
	DataTable,
	FilterBar,
	IconAlertTriangle,
	IconEdit,
	IconEye,
	IconFileSpreadsheet,
	IconFileText,
	IconPlus,
	IconTrash,
	PageHeader,
	RowAction,
	Select,
	type DataTableColumn,
} from "@shared/ui";
import { StatusBadge } from "@shared/ui/StatusBadge";
import { useToast } from "@shared/ui/Toast";
import { useCan } from "@shared/api/access";
import { risksApi, risksDashboardApi } from '@entities/risk';
import type { RiskTypeValue, RiskStatusValue, RiskLevelValue } from "@entities/risk";

type Risk = {
  id: number;
  code: string;
  title: string;
  status: RiskStatusValue;
  status_label: string;
  current_level: RiskLevelValue;
  current_score: number;
  type: RiskTypeValue;
  type_label: string;
  discovery_date: string;
  target_close_date?: string | null;
  department?: { id: number; name: string } | null;
  owner?: { id: number; name: string } | null;
  open_actions_count?: number;
  // Per-record abilities — set server-side via ElementAbilities. Used by the
  // row-level Edit/Delete gates (Phase 9.3 freeze cleanup 2026-07-06).
  abilities?: {
    view?: boolean;
    edit?: boolean;
    delete?: boolean;
    change_status?: boolean;
    reassess?: boolean;
  };
};

const LEVEL_COLOR: Record<string, "success" | "info" | "warning" | "danger"> = {
  low: "success",
  medium: "info",
  high: "warning",
  critical: "danger",
};

const STATUS_COLOR: Record<string, "danger" | "warning" | "success" | "secondary"> = {
  open: "danger",
  treating: "warning",
  closed: "success",
  accepted: "secondary",
};

const LEVEL_OPTIONS = [
  { value: "", label: "كل المستويات" },
  { value: "low", label: "منخفض" },
  { value: "medium", label: "متوسط" },
  { value: "high", label: "عالٍ" },
  { value: "critical", label: "حرج" },
];

const LEVEL_LABEL: Record<string, string> = {
  low: "منخفض",
  medium: "متوسط",
  high: "عالٍ",
  critical: "حرج",
};

const STATUS_OPTIONS = [
  { value: "", label: "كل الحالات" },
  { value: "open", label: "مفتوح" },
  { value: "treating", label: "قيد المعالجة" },
  { value: "closed", label: "مغلق" },
  { value: "accepted", label: "مقبول" },
];

const TYPE_OPTIONS = [
  { value: "", label: "كل الأنواع" },
  { value: "operational", label: "تشغيلي" },
  { value: "clinical", label: "سريري" },
  { value: "financial", label: "مالي" },
  { value: "technical", label: "تقني" },
  { value: "compliance", label: "امتثال" },
  { value: "reputational", label: "سمعة" },
];

export const RisksListPage: React.FC = () => {
  const canViewReports = useCan('risks.view_reports');
  const canCreate = useCan('risks.create');
  // Phase 9.3 freeze cleanup (2026-07-06): row-level Edit/Delete gates use
  // canonical capabilities. The row's `abilities.{edit,delete}` payload (set
  // server-side via ElementAbilities) refines "can I act on THIS risk" — it
  // is consulted in the action callback below.
  const canEditRisk = useCan('risks.edit');
  const canDeleteRisk = useCan('risks.delete');
  const navigate = useNavigate();
  const { showToast } = useToast();

  const [risks, setRisks] = useState<Risk[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [pagination, setPagination] = useState({ currentPage: 1, lastPage: 1, perPage: 15, total: 0 });
  const [filters, setFilters] = useState({ search: "", level: "", status: "", type: "" });
  const [searchInput, setSearchInput] = useState("");

  const fetchRisks = async (page = 1) => {
    setIsLoading(true);
    try {
      const params: Record<string, string> = { page: String(page), per_page: "15" };
      if (filters.search) params.search = filters.search;
      if (filters.level) params.level = filters.level;
      if (filters.status) params.status = filters.status;
      if (filters.type) params.type = filters.type;
      const res: any = await risksApi.list(params);
      setRisks(res.data ?? res);
      setPagination({
        currentPage: res.current_page ?? 1,
        lastPage: res.last_page ?? 1,
        perPage: res.per_page ?? 15,
        total: res.total ?? (res.data?.length ?? 0),
      });
    } catch (err: any) {
      showToast("error", err?.response?.data?.message ?? "حدث خطأ");
    } finally {
      setIsLoading(false);
    }
  };

  useEffect(() => { fetchRisks(1);   }, [filters]);

  useEffect(() => {
    const id = setTimeout(() => {
      setFilters((f) => (f.search === searchInput ? f : { ...f, search: searchInput }));
    }, 350);
    return () => clearTimeout(id);
  }, [searchInput]);

  const clearFilters = () => {
    setSearchInput("");
    setFilters({ search: "", level: "", status: "", type: "" });
  };

  const hasActiveFilters = !!(filters.search || filters.level || filters.status || filters.type);

  const columns: DataTableColumn<Risk>[] = [
    {
      key: "code",
      header: "الرمز",
      width: "w-36",
      render: (r) => <span className="font-mono text-xs text-[var(--text-tertiary)]">{r.code}</span>,
    },
    {
      key: "title",
      header: "العنوان",
      render: (r) => (
        <span className="font-semibold text-[var(--text-primary)] transition-colors group-hover:text-[var(--accent-default)]">
          {r.title}
        </span>
      ),
    },
    {
      key: "type",
      header: "النوع",
      hideBelow: "lg",
      render: (r) => <span className="text-sm text-[var(--text-secondary)]">{r.type_label}</span>,
    },
    {
      key: "level",
      header: "المستوى",
      render: (r) => (
        <StatusBadge
          type="custom"
          status={r.current_level}
          label={LEVEL_LABEL[r.current_level] ?? r.current_level}
          color={LEVEL_COLOR[r.current_level] ?? "secondary"}
        />
      ),
    },
    {
      key: "score",
      header: "الدرجة",
      align: "center",
      hideBelow: "sm",
      render: (r) => <span className="text-sm tabular-nums text-[var(--text-secondary)]">{r.current_score}</span>,
    },
    {
      key: "status",
      header: "الحالة",
      render: (r) => (
        <StatusBadge type="custom" status={r.status} label={r.status_label} color={STATUS_COLOR[r.status] ?? "secondary"} />
      ),
    },
    {
      key: "open_actions",
      header: "إجراءات مفتوحة",
      align: "center",
      hideBelow: "md",
      render: (r) => <span className="text-sm tabular-nums text-[var(--text-secondary)]">{r.open_actions_count ?? 0}</span>,
    },
  ];

  return (
    <div className="space-y-4 sm:space-y-6">
      <PageHeader
        title="إدارة المخاطر المؤسسية"
        subtitle="سجل المخاطر المؤسسية ومتابعة معالجتها"
        icon={IconAlertTriangle}
        iconTone="risk"
        actions={
          <>
            {canViewReports && (
              <>
                <a href={risksDashboardApi.exportUrl("csv")} download>
                  <Button variant="secondary" size="sm" leftIcon={<IconFileSpreadsheet className="h-4 w-4" />}>CSV</Button>
                </a>
                <a href={risksDashboardApi.exportUrl("pdf")} download>
                  <Button variant="secondary" size="sm" leftIcon={<IconFileText className="h-4 w-4" />}>PDF</Button>
                </a>
              </>
            )}
            {canCreate && (
              <Button size="sm" leftIcon={<IconPlus className="h-4 w-4" />} onClick={() => navigate("/risk-management/create")}>
                تسجيل خطر جديد
              </Button>
            )}
          </>
        }
      />

      <DataTable
        data={risks}
        loading={isLoading}
        rowKey={(r) => r.id}
        columns={columns}
        rowHref={(r) => `/risk-management/risks/${r.id}`}
        pagination={{
          currentPage: pagination.currentPage,
          lastPage: pagination.lastPage,
          total: pagination.total,
          onPageChange: (page) => fetchRisks(page),
        }}
        toolbar={
          <FilterBar
            search={searchInput}
            onSearchChange={setSearchInput}
            searchPlaceholder="بحث بالعنوان أو الرمز..."
            hasActiveFilters={hasActiveFilters}
            onClear={clearFilters}
          >
            <Select value={filters.level} onChange={(e) => setFilters((f) => ({ ...f, level: e.target.value }))} options={LEVEL_OPTIONS} />
            <Select value={filters.status} onChange={(e) => setFilters((f) => ({ ...f, status: e.target.value }))} options={STATUS_OPTIONS} />
            <Select value={filters.type} onChange={(e) => setFilters((f) => ({ ...f, type: e.target.value }))} options={TYPE_OPTIONS} />
          </FilterBar>
        }
        empty={{
          icon: IconAlertTriangle,
          title: "لا توجد مخاطر مسجلة",
          description: "ابدأ بتسجيل أول خطر لمتابعته وتقييمه.",
          action: canCreate ? (
            <Button leftIcon={<IconPlus className="h-4 w-4" />} onClick={() => navigate("/risk-management/create")}>
              تسجيل خطر جديد
            </Button>
          ) : undefined,
        }}
        actions={(r) => (
          <>
            <RowAction icon={IconEye} label="عرض" to={`/risk-management/risks/${r.id}`} />
            {canEditRisk && r.abilities?.edit !== false && (
              <RowAction
                icon={IconEdit}
                label="تعديل"
                to={`/risk-management/risks/${r.id}/edit`}
              />
            )}
            {canDeleteRisk && r.abilities?.delete !== false && (
              <RowAction
                icon={IconTrash}
                label="حذف"
                tone="danger"
                onClick={() => {
                  if (typeof globalThis !== 'undefined' && globalThis.confirm) {
                    if (globalThis.confirm('هل تريد حذف هذا الخطر؟')) {
                      showToast('info', 'سيتم تنفيذ الحذف عند إضافة التكامل مع المخاطر.');
                    }
                  }
                }}
              />
            )}
          </>
        )}
      />
    </div>
  );
};

export default RisksListPage;
