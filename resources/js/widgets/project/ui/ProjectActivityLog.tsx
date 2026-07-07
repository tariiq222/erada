import React, { useEffect, useState, useCallback } from 'react';
import { projectsApi } from '@entities/project';
import {
  Card,
  CardContent,
  Badge,
  Button,
  Select,
  Skeleton,
  EmptyState,
} from '@shared/ui';
import {IconHistory, IconUser, IconClock, IconPlus, IconPencil, IconTrash, IconRotateClockwise, IconChevronDown, IconChevronUp, IconArrowRight, IconCalendar, IconRefresh, IconSquareCheck, IconLayoutKanban, IconUserPlus, IconUserMinus, IconUsers, IconTarget, IconAlertTriangle, IconCurrencyDollar, IconMessage, IconPaperclip} from '@tabler/icons-react';

interface ActivityChange {
  field: string;
  field_label: string;
  old_value: any;
  new_value: any;
  display_old: string;
  display_new: string;
}

interface ActivityLogItem {
  id: number;
  action: string;
  action_label: string;
  loggable_type: 'task' | 'project';
  loggable_type_label: string;
  task_title: string | null;
  user: { id: number; name: string } | null;
  changes: ActivityChange[];
  old_values: Record<string, any> | null;
  new_values: Record<string, any> | null;
  ip_address: string | null;
  created_at: string;
  created_at_formatted: string;
  created_at_human: string;
}

interface PaginatedResponse {
  data: ActivityLogItem[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

interface ProjectActivityLogProps {
  projectId: number;
}

const actionIcons: Record<string, React.FC<{ className?: string }>> = {
  // العمليات الأساسية
  created: IconPlus,
  updated: IconPencil,
  deleted: IconTrash,
  restored: IconRotateClockwise,
  // الأعضاء
  member_added: IconUserPlus,
  member_removed: IconUserMinus,
  // أصحاب المصلحة
  stakeholder_added: IconUsers,
  stakeholder_updated: IconUsers,
  stakeholder_deleted: IconUsers,
  // مؤشرات الأداء
  kpi_added: IconTarget,
  kpi_updated: IconTarget,
  kpi_deleted: IconTarget,
  // المخاطر
  risk_added: IconAlertTriangle,
  risk_updated: IconAlertTriangle,
  risk_deleted: IconAlertTriangle,
  // المصروفات
  expense_added: IconCurrencyDollar,
  expense_updated: IconCurrencyDollar,
  expense_deleted: IconCurrencyDollar,
  // التعليقات والمرفقات
  comment_added: IconMessage,
  comment_deleted: IconMessage,
  attachment_added: IconPaperclip,
  attachment_deleted: IconPaperclip,
  // المهام الفرعية
  subtask_created: IconSquareCheck,
  subtask_updated: IconSquareCheck,
  subtask_deleted: IconSquareCheck,
};

const actionColors: Record<string, { bg: string; text: string; border: string }> = {
  // العمليات الأساسية
  created: { bg: 'bg-[var(--status-success-subtle)]', text: 'text-[var(--status-success)]', border: 'border-[var(--status-success-subtle)]' },
  updated: { bg: 'bg-[var(--accent-subtle)]', text: 'text-[var(--accent-default)]', border: 'border-[var(--accent-subtle)]' },
  deleted: { bg: 'bg-[var(--status-danger-subtle)]', text: 'text-[var(--status-danger)]', border: 'border-[var(--status-danger-subtle)]' },
  restored: { bg: 'bg-[var(--accent-subtle)]', text: 'text-[var(--accent-default)]', border: 'border-[var(--accent-subtle)]' },
  // الأعضاء
  member_added: { bg: 'bg-[var(--accent-subtle)]', text: 'text-[var(--accent-default)]', border: 'border-[var(--accent-subtle)]' },
  member_removed: { bg: 'bg-[var(--status-warning-subtle)]', text: 'text-[var(--status-warning)]', border: 'border-[var(--status-warning-subtle)]' },
  // أصحاب المصلحة
  stakeholder_added: { bg: 'bg-[var(--accent-subtle)]', text: 'text-[var(--accent-default)]', border: 'border-[var(--accent-subtle)]' },
  stakeholder_updated: { bg: 'bg-[var(--accent-subtle)]', text: 'text-[var(--accent-default)]', border: 'border-[var(--accent-subtle)]' },
  stakeholder_deleted: { bg: 'bg-[var(--status-warning-subtle)]', text: 'text-[var(--status-warning)]', border: 'border-[var(--status-warning-subtle)]' },
  // مؤشرات الأداء
  kpi_added: { bg: 'bg-[var(--accent-subtle)]', text: 'text-[var(--accent-default)]', border: 'border-[var(--accent-subtle)]' },
  kpi_updated: { bg: 'bg-[var(--accent-subtle)]', text: 'text-[var(--accent-default)]', border: 'border-[var(--accent-subtle)]' },
  kpi_deleted: { bg: 'bg-[var(--status-warning-subtle)]', text: 'text-[var(--status-warning)]', border: 'border-[var(--status-warning-subtle)]' },
  // المخاطر
  risk_added: { bg: 'bg-[var(--status-warning-subtle)]', text: 'text-[var(--status-warning)]', border: 'border-[var(--status-warning-subtle)]' },
  risk_updated: { bg: 'bg-[var(--status-warning-subtle)]', text: 'text-[var(--status-warning)]', border: 'border-[var(--status-warning-subtle)]' },
  risk_deleted: { bg: 'bg-[var(--status-warning-subtle)]', text: 'text-[var(--status-warning)]', border: 'border-[var(--status-warning-subtle)]' },
  // المصروفات
  expense_added: { bg: 'bg-[var(--status-success-subtle)]', text: 'text-[var(--status-success)]', border: 'border-[var(--status-success-subtle)]' },
  expense_updated: { bg: 'bg-[var(--status-success-subtle)]', text: 'text-[var(--status-success)]', border: 'border-[var(--status-success-subtle)]' },
  expense_deleted: { bg: 'bg-[var(--status-danger-subtle)]', text: 'text-[var(--status-danger)]', border: 'border-[var(--status-danger-subtle)]' },
  // التعليقات والمرفقات
  comment_added: { bg: 'bg-[var(--accent-subtle)]', text: 'text-[var(--accent-default)]', border: 'border-[var(--accent-subtle)]' },
  comment_deleted: { bg: 'bg-[var(--status-warning-subtle)]', text: 'text-[var(--status-warning)]', border: 'border-[var(--status-warning-subtle)]' },
  attachment_added: { bg: 'bg-[var(--accent-subtle)]', text: 'text-[var(--accent-default)]', border: 'border-[var(--accent-subtle)]' },
  attachment_deleted: { bg: 'bg-[var(--status-warning-subtle)]', text: 'text-[var(--status-warning)]', border: 'border-[var(--status-warning-subtle)]' },
  // المهام الفرعية
  subtask_created: { bg: 'bg-[var(--status-success-subtle)]', text: 'text-[var(--status-success)]', border: 'border-[var(--status-success-subtle)]' },
  subtask_updated: { bg: 'bg-[var(--accent-subtle)]', text: 'text-[var(--accent-default)]', border: 'border-[var(--accent-subtle)]' },
  subtask_deleted: { bg: 'bg-[var(--status-danger-subtle)]', text: 'text-[var(--status-danger)]', border: 'border-[var(--status-danger-subtle)]' },
};

const ProjectActivityLog: React.FC<ProjectActivityLogProps> = ({ projectId }) => {
  const [logs, setLogs] = useState<ActivityLogItem[]>([]);
  const [loading, setLoading] = useState(true);
  const [loadingMore, setLoadingMore] = useState(false);
  const [pagination, setPagination] = useState<Omit<PaginatedResponse, 'data'> | null>(null);
  const [actionFilter, setActionFilter] = useState<string>('all');
  const [expandedItems, setExpandedItems] = useState<Set<number>>(new Set());

  const fetchLogs = useCallback(async (page = 1, append = false) => {
    try {
      if (append) {
        setLoadingMore(true);
      } else {
        setLoading(true);
      }

      const params: Record<string, string> = { page: String(page), per_page: '15' };
      if (actionFilter !== 'all') {
        params.action = actionFilter;
      }

      const response = await projectsApi.getActivityLog(projectId, params) as PaginatedResponse;

      if (append) {
        setLogs(prev => [...prev, ...response.data]);
      } else {
        setLogs(response.data);
      }

      setPagination({
        current_page: response.current_page,
        last_page: response.last_page,
        per_page: response.per_page,
        total: response.total,
      });
    } catch (error) {
      console.error('Failed to fetch activity logs:', error);
    } finally {
      setLoading(false);
      setLoadingMore(false);
    }
  }, [projectId, actionFilter]);

  useEffect(() => {
    fetchLogs();
  }, [fetchLogs]);

  const toggleExpand = (id: number) => {
    setExpandedItems(prev => {
      const newSet = new Set(prev);
      if (newSet.has(id)) {
        newSet.delete(id);
      } else {
        newSet.add(id);
      }
      return newSet;
    });
  };

  const loadMore = () => {
    if (pagination && pagination.current_page < pagination.last_page) {
      fetchLogs(pagination.current_page + 1, true);
    }
  };

  if (loading) {
    return (
      <div className="space-y-4">
        {/* Header */}
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-2">
            <IconHistory className="h-5 w-5 text-[var(--text-secondary)]" />
            <div>
              <h3 className="font-semibold text-[var(--text-primary)]">سجل النشاطات</h3>
              <p className="text-sm text-[var(--text-tertiary)]">جاري التحميل...</p>
            </div>
          </div>
        </div>
        <Card>
          <CardContent className="p-4">
            <div className="space-y-4">
              {[1, 2, 3].map((i) => (
                <div key={i} className="flex gap-4">
                  <Skeleton width={40} height={40} className="rounded-full" />
                  <div className="flex-1">
                    <Skeleton width={200} height={20} className="mb-2" />
                    <Skeleton width={300} height={16} />
                  </div>
                </div>
              ))}
            </div>
          </CardContent>
        </Card>
      </div>
    );
  }

  return (
    <div className="space-y-4">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-2">
          <IconHistory className="h-5 w-5 text-[var(--text-secondary)]" />
          <div>
            <h3 className="font-semibold text-[var(--text-primary)]">سجل النشاطات</h3>
            <p className="text-sm text-[var(--text-tertiary)]">{pagination?.total || 0} سجل</p>
          </div>
        </div>
        <div className="flex items-center gap-2">
          <Select
            value={actionFilter}
            onChange={(e) => setActionFilter(e.target.value)}
            options={[
              { value: 'all', label: 'كل الأنشطة' },
              // العمليات الأساسية
              { value: 'created', label: 'الإنشاء' },
              { value: 'updated', label: 'التحديث' },
              { value: 'deleted', label: 'الحذف' },
              { value: 'restored', label: 'الاستعادة' },
              // الأعضاء
              { value: 'member_added', label: 'إضافة عضو' },
              { value: 'member_removed', label: 'إزالة عضو' },
              // أصحاب المصلحة
              { value: 'stakeholder_added', label: 'إضافة صاحب مصلحة' },
              { value: 'stakeholder_updated', label: 'تحديث صاحب مصلحة' },
              { value: 'stakeholder_deleted', label: 'حذف صاحب مصلحة' },
              // مؤشرات الأداء
              { value: 'kpi_added', label: 'إضافة مؤشر أداء' },
              { value: 'kpi_updated', label: 'تحديث مؤشر أداء' },
              { value: 'kpi_deleted', label: 'حذف مؤشر أداء' },
              // المخاطر
              { value: 'risk_added', label: 'إضافة خطر' },
              { value: 'risk_updated', label: 'تحديث خطر' },
              { value: 'risk_deleted', label: 'حذف خطر' },
              // المصروفات
              { value: 'expense_added', label: 'إضافة مصروف' },
              { value: 'expense_updated', label: 'تحديث مصروف' },
              { value: 'expense_deleted', label: 'حذف مصروف' },
              // التعليقات والمرفقات
              { value: 'comment_added', label: 'إضافة تعليق' },
              { value: 'comment_deleted', label: 'حذف تعليق' },
              { value: 'attachment_added', label: 'رفع مرفق' },
              { value: 'attachment_deleted', label: 'حذف مرفق' },
              // المهام الفرعية
              { value: 'subtask_created', label: 'إنشاء مهمة فرعية' },
              { value: 'subtask_updated', label: 'تحديث مهمة فرعية' },
              { value: 'subtask_deleted', label: 'حذف مهمة فرعية' },
            ]}
          />
          <Button
            variant="outline"
            size="sm"
            onClick={() => fetchLogs()}
            leftIcon={<IconRefresh className="h-4 w-4" />}
          >
            تحديث
          </Button>
        </div>
      </div>

      {/* Content */}
      {logs.length === 0 ? (
        <Card>
          <CardContent className="p-4">
            <EmptyState
              icon={IconHistory}
              title="لا توجد سجلات"
              description="لم يتم تسجيل أي نشاط بعد"
              size="lg"
            />
          </CardContent>
        </Card>
      ) : (
        <div className="relative">
          {/* Timeline Line */}
          <div className="absolute top-0 bottom-0 right-5 w-0.5 bg-[var(--surface-muted)]" />

          <div className="space-y-6">
              {logs.map((log) => {
                const ActionIcon = actionIcons[log.action] || IconPencil;
                const colors = actionColors[log.action] || actionColors.updated;
                const isExpanded = expandedItems.has(log.id);
                const hasChanges = log.changes && log.changes.length > 0;

                return (
                  <div key={log.id} className="relative flex gap-4">
                    {/* Timeline Dot */}
                    <div
                      className={`relative z-10 flex-shrink-0 w-10 h-10 rounded-full ${colors.bg} border-2 ${colors.border} flex items-center justify-center`}
                    >
                      <ActionIcon className={`h-4 w-4 ${colors.text}`} />
                    </div>

                    {/* Content */}
                    <div className="flex-1 min-w-0">
                      <div
                        className={`bg-[var(--surface-base)] rounded-lg border border-[var(--border-default)] p-4 hover:shadow-sm transition-shadow ${
                          hasChanges ? 'cursor-pointer' : ''
                        }`}
                        onClick={() => hasChanges && toggleExpand(log.id)}
                      >
                        {/* Header */}
                        <div className="flex items-start justify-between gap-4">
                          <div className="flex-1 min-w-0">
                            <div className="flex items-center gap-2 flex-wrap">
                              <Badge variant={
                                log.action.includes('deleted') || log.action.includes('removed') ? 'danger' :
                                log.action.includes('created') || log.action.includes('added') ? 'success' :
                                'accent'
                              }>
                                {log.action_label}
                              </Badge>
                              {/* نوع العنصر: مشروع أو مهمة */}
                              <span className={`inline-flex items-center gap-1 px-2 py-0 rounded text-xs font-medium ${
                                log.loggable_type === 'task'
                                  ? 'bg-[var(--status-warning-subtle)] text-[var(--status-warning-text)]'
                                  : 'bg-[var(--accent-subtle)] text-[var(--accent-default)]'
                              }`}>
                                {log.loggable_type === 'task' ? (
                                  <IconSquareCheck className="h-3 w-3" />
                                ) : (
                                  <IconLayoutKanban className="h-3 w-3" />
                                )}
                                {log.loggable_type_label}
                              </span>
                              <span className="flex items-center gap-1 text-sm text-[var(--text-secondary)]">
                                <IconUser className="h-3.5 w-3.5" />
                                {log.user?.name || 'مستخدم غير معروف'}
                              </span>
                            </div>
                            {/* اسم المهمة إذا كان السجل لمهمة */}
                            {log.loggable_type === 'task' && log.task_title && (
                              <div className="mt-1 text-sm font-medium text-[var(--text-primary)]">
                                {log.task_title}
                              </div>
                            )}
                            <div className="flex items-center gap-3 mt-2 text-xs text-[var(--text-tertiary)]">
                              <span className="flex items-center gap-1">
                                <IconCalendar className="h-3 w-3" />
                                {log.created_at_formatted}
                              </span>
                              <span className="flex items-center gap-1">
                                <IconClock className="h-3 w-3" />
                                {log.created_at_human}
                              </span>
                            </div>
                          </div>

                          {hasChanges && (
                            <button className="text-[var(--text-tertiary)] hover:text-[var(--text-secondary)] transition-colors">
                              {isExpanded ? (
                                <IconChevronUp className="h-5 w-5" />
                              ) : (
                                <IconChevronDown className="h-5 w-5" />
                              )}
                            </button>
                          )}
                        </div>

                        {/* Changes Summary (collapsed) */}
                        {hasChanges && !isExpanded && (
                          <div className="mt-3 pt-3 border-t border-[var(--border-default)]">
                            <p className="text-sm text-[var(--text-secondary)]">
                              تم تغيير: {log.changes.map(c => c.field_label).join('، ')}
                            </p>
                          </div>
                        )}

                        {/* Changes Details (expanded) */}
                        {hasChanges && isExpanded && (
                          <div className="mt-4 pt-4 border-t border-[var(--border-default)] space-y-3">
                            {log.changes.map((change, idx) => (
                              <div
                                key={idx}
                                className="bg-[var(--surface-subtle)] rounded-lg p-3"
                              >
                                <div className="text-xs font-medium text-[var(--text-tertiary)] mb-2">
                                  {change.field_label}
                                </div>
                                <div className="flex items-center gap-2 text-sm">
                                  <span className="px-2 py-1 bg-[var(--status-danger-subtle)] text-[var(--status-danger-text)] rounded line-through">
                                    {change.display_old || 'غير محدد'}
                                  </span>
                                  <IconArrowRight className="h-4 w-4 text-[var(--text-tertiary)]" />
                                  <span className="px-2 py-1 bg-[var(--status-success-subtle)] text-[var(--status-success-text)] rounded font-medium">
                                    {change.display_new || 'غير محدد'}
                                  </span>
                                </div>
                              </div>
                            ))}
                          </div>
                        )}

                        {/* Created action - show initial values */}
                        {log.action === 'created' && log.new_values && log.loggable_type === 'project' && (
                          <div className="mt-3 pt-3 border-t border-[var(--border-default)]">
                            <p className="text-sm text-[var(--text-secondary)]">
                              تم إنشاء المشروع برمز: <span className="font-medium">{log.new_values.code}</span>
                            </p>
                          </div>
                        )}
                        {/* Created task - show task title */}
                        {log.action === 'created' && log.loggable_type === 'task' && !log.task_title && log.new_values?.title && (
                          <div className="mt-3 pt-3 border-t border-[var(--border-default)]">
                            <p className="text-sm text-[var(--text-secondary)]">
                              تم إنشاء مهمة: <span className="font-medium">{log.new_values.title}</span>
                            </p>
                          </div>
                        )}

                        {/* عرض تفاصيل عمليات الأعضاء */}
                        {(log.action === 'member_added' || log.action === 'member_removed') && log.new_values && (
                          <div className="mt-3 pt-3 border-t border-[var(--border-default)]">
                            <p className="text-sm text-[var(--text-secondary)]">
                              {log.action === 'member_added' ? 'تم إضافة' : 'تم إزالة'}: {' '}
                              <span className="font-medium">{log.new_values.member_name}</span>
                              {log.new_values.role && (
                                <span className="text-[var(--text-tertiary)]"> ({log.new_values.role})</span>
                              )}
                            </p>
                          </div>
                        )}

                        {/* عرض تفاصيل أصحاب المصلحة */}
                        {log.action.startsWith('stakeholder_') && log.new_values && (
                          <div className="mt-3 pt-3 border-t border-[var(--border-default)]">
                            <p className="text-sm text-[var(--text-secondary)]">
                              {log.new_values.stakeholder_name && (
                                <>صاحب المصلحة: <span className="font-medium">{log.new_values.stakeholder_name}</span></>
                              )}
                              {log.new_values.stakeholder_role && (
                                <span className="text-[var(--text-tertiary)]"> - {log.new_values.stakeholder_role}</span>
                              )}
                            </p>
                          </div>
                        )}

                        {/* عرض تفاصيل مؤشرات الأداء */}
                        {log.action.startsWith('kpi_') && log.new_values && (
                          <div className="mt-3 pt-3 border-t border-[var(--border-default)]">
                            <p className="text-sm text-[var(--text-secondary)]">
                              {log.new_values.indicator && (
                                <>المؤشر: <span className="font-medium">{log.new_values.indicator}</span></>
                              )}
                              {log.new_values.target && (
                                <span className="text-[var(--text-tertiary)]"> - الهدف: {log.new_values.target}</span>
                              )}
                            </p>
                          </div>
                        )}

                        {/* عرض تفاصيل المخاطر */}
                        {log.action.startsWith('risk_') && log.new_values && (
                          <div className="mt-3 pt-3 border-t border-[var(--border-default)]">
                            <p className="text-sm text-[var(--text-secondary)]">
                              {log.new_values.risk && (
                                <>الخطر: <span className="font-medium">{log.new_values.risk}</span></>
                              )}
                              {log.new_values.probability && (
                                <span className="text-[var(--text-tertiary)]"> - الاحتمالية: {log.new_values.probability}</span>
                              )}
                              {log.new_values.impact && (
                                <span className="text-[var(--text-tertiary)]"> - التأثير: {log.new_values.impact}</span>
                              )}
                            </p>
                          </div>
                        )}

                        {/* عرض تفاصيل المرفقات */}
                        {log.action.startsWith('attachment_') && log.new_values && (
                          <div className="mt-3 pt-3 border-t border-[var(--border-default)]">
                            <p className="text-sm text-[var(--text-secondary)]">
                              {log.new_values.file_name && (
                                <>الملف: <span className="font-medium">{log.new_values.file_name}</span></>
                              )}
                              {log.new_values.file_size && (
                                <span className="text-[var(--text-tertiary)]"> ({Math.round(log.new_values.file_size / 1024)} KB)</span>
                              )}
                            </p>
                          </div>
                        )}

                        {/* عرض تفاصيل التعليقات */}
                        {log.action.startsWith('comment_') && log.new_values && (
                          <div className="mt-3 pt-3 border-t border-[var(--border-default)]">
                            {log.new_values.content && (
                              <p className="text-sm text-[var(--text-secondary)] line-clamp-2">
                                {log.new_values.content}
                              </p>
                            )}
                          </div>
                        )}

                        {/* عرض تفاصيل المصروفات */}
                        {log.action.startsWith('expense_') && log.new_values && (
                          <div className="mt-3 pt-3 border-t border-[var(--border-default)]">
                            <p className="text-sm text-[var(--text-secondary)]">
                              {log.new_values.title && (
                                <>المصروف: <span className="font-medium">{log.new_values.title}</span></>
                              )}
                              {log.new_values.amount && (
                                <span className="text-[var(--text-tertiary)]"> - المبلغ: {log.new_values.amount} ريال</span>
                              )}
                            </p>
                          </div>
                        )}
                      </div>
                    </div>
                  </div>
                );
              })}
          </div>

          {/* Load More */}
          {pagination && pagination.current_page < pagination.last_page && (
            <div className="mt-6 text-center">
              <Button
                variant="outline"
                onClick={loadMore}
                loading={loadingMore}
              >
                تحميل المزيد ({pagination.total - logs.length} سجل متبقي)
              </Button>
            </div>
          )}
        </div>
      )}
    </div>
  );
};

export default ProjectActivityLog;
