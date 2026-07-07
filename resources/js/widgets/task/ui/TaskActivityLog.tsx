import React, { useState, useEffect } from 'react';
import {IconActivity, IconPencil, IconCircleCheck, IconMessage, IconUserPlus, IconFlag, IconCalendar, IconLoader, IconChevronDown, IconChevronUp, IconPlus, IconTrash, IconPaperclip, IconListTree} from '@tabler/icons-react';
import { tasksApi } from '@entities/task';
import { formatDate, formatDateTime } from '@shared/lib/utils';
import { EmptyState } from '@shared/ui';

// Shape returned by the unified `/unified-tasks/{id}/activity-log` endpoint:
// a full ActivityLog model with an eager-loaded `user` (whole User object) and
// extra fields (`description`, `loggable_type`, etc.). We read only `user.name`
// / `user.id` plus the change values, so the extra fields are tolerated.
interface ActivityUser {
  id: number;
  name: string;
  [key: string]: unknown;
}

interface ActivityItem {
  id: number;
  action: string;
  description?: string | null;
  user: ActivityUser | null;
  old_values: Record<string, any> | null;
  new_values: Record<string, any> | null;
  created_at: string;
  [key: string]: unknown;
}

interface TaskActivityLogProps {
  taskId: number;
  maxItems?: number;
  showHeader?: boolean;
  compact?: boolean;
}

const activityIcons: Record<string, React.FC<{ className?: string }>> = {
  created: IconPlus,
  updated: IconPencil,
  deleted: IconTrash,
  status_changed: IconCircleCheck,
  assigned: IconUserPlus,
  priority_changed: IconFlag,
  due_date_changed: IconCalendar,
  comment_added: IconMessage,
  comment_deleted: IconTrash,
  attachment_added: IconPaperclip,
  attachment_deleted: IconTrash,
  subtask_created: IconListTree,
  subtask_updated: IconPencil,
  subtask_deleted: IconTrash,
  default: IconActivity,
};

const activityColors: Record<string, { bg: string; text: string; icon: string }> = {
  created: {
    bg: 'bg-[var(--status-success-subtle)]',
    text: 'text-[var(--status-success-text)]',
    icon: 'text-[var(--status-success)]',
  },
  updated: {
    bg: 'bg-[var(--accent-subtle)]',
    text: 'text-[var(--accent-default)]',
    icon: 'text-[var(--accent-default)]',
  },
  deleted: {
    bg: 'bg-[var(--status-danger-subtle)]',
    text: 'text-[var(--status-danger-text)]',
    icon: 'text-[var(--status-danger)]',
  },
  status_changed: {
    bg: 'bg-[var(--accent-subtle)]',
    text: 'text-[var(--accent-default)]',
    icon: 'text-[var(--accent-default)]',
  },
  assigned: {
    bg: 'bg-[var(--accent-subtle)]',
    text: 'text-[var(--accent-default)]',
    icon: 'text-[var(--accent-default)]',
  },
  priority_changed: {
    bg: 'bg-[var(--status-warning-subtle)]',
    text: 'text-[var(--status-warning-text)]',
    icon: 'text-[var(--status-warning)]',
  },
  due_date_changed: {
    bg: 'bg-[var(--status-warning-subtle)]',
    text: 'text-[var(--status-warning-text)]',
    icon: 'text-[var(--status-warning)]',
  },
  comment_added: {
    bg: 'bg-[var(--accent-subtle)]',
    text: 'text-[var(--accent-default)]',
    icon: 'text-[var(--accent-default)]',
  },
  comment_deleted: {
    bg: 'bg-[var(--status-danger-subtle)]',
    text: 'text-[var(--status-danger-text)]',
    icon: 'text-[var(--status-danger)]',
  },
  attachment_added: {
    bg: 'bg-[var(--accent-subtle)]',
    text: 'text-[var(--accent-default)]',
    icon: 'text-[var(--accent-default)]',
  },
  attachment_deleted: {
    bg: 'bg-[var(--status-danger-subtle)]',
    text: 'text-[var(--status-danger-text)]',
    icon: 'text-[var(--status-danger)]',
  },
  subtask_created: {
    bg: 'bg-[var(--accent-subtle)]',
    text: 'text-[var(--accent-default)]',
    icon: 'text-[var(--accent-default)]',
  },
  subtask_updated: {
    bg: 'bg-[var(--accent-subtle)]',
    text: 'text-[var(--accent-default)]',
    icon: 'text-[var(--accent-default)]',
  },
  subtask_deleted: {
    bg: 'bg-[var(--status-danger-subtle)]',
    text: 'text-[var(--status-danger-text)]',
    icon: 'text-[var(--status-danger)]',
  },
  default: {
    bg: 'bg-[var(--surface-muted)]',
    text: 'text-[var(--text-secondary)]',
    icon: 'text-[var(--text-secondary)]',
  },
};

const formatFullDateTime = (dateString: string) =>
  formatDateTime(dateString, {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
  });

// تنسيق الوقت النسبي مع إظهار التاريخ الكامل دائماً
const formatRelativeTime = (dateString: string) => {
  const date = new Date(dateString);
  const now = new Date();
  const diff = now.getTime() - date.getTime();
  const minutes = Math.floor(diff / 60000);
  const hours = Math.floor(diff / 3600000);
  const days = Math.floor(diff / 86400000);

  // دائماً نُرجع التاريخ والوقت الكامل
  const fullDateTime = formatFullDateTime(dateString);

  if (minutes < 1) return { relative: 'الآن', full: fullDateTime };
  if (minutes < 60) return { relative: `منذ ${minutes} دقيقة`, full: fullDateTime };
  if (hours < 24) return { relative: `منذ ${hours} ساعة`, full: fullDateTime };
  if (days < 7) return { relative: `منذ ${days} يوم`, full: fullDateTime };
  return { relative: fullDateTime, full: fullDateTime };
};

// ترجمة أسماء الحقول
const fieldLabels: Record<string, string> = {
  title: 'العنوان',
  description: 'الوصف',
  status: 'الحالة',
  priority: 'الأولوية',
  assigned_to: 'المكلف',
  due_date: 'تاريخ الاستحقاق',
  start_date: 'تاريخ البدء',
  completed_date: 'تاريخ الإكمال',
  estimated_hours: 'الساعات المتوقعة',
  actual_hours: 'الساعات الفعلية',
  milestone_id: 'المرحلة',
  parent_id: 'المهمة الأم',
};

// ترجمة قيم الحالة
const statusLabels: Record<string, string> = {
  todo: 'للتنفيذ',
  in_progress: 'قيد التنفيذ',
  in_review: 'قيد المراجعة',
  completed: 'مكتملة',
};

// ترجمة قيم الأولوية
const priorityLabels: Record<string, string> = {
  low: 'منخفضة',
  medium: 'متوسطة',
  high: 'عالية',
  urgent: 'عاجلة',
};

// تحويل القيمة لعرض مناسب
const formatValue = (key: string, value: any): string => {
  if (value === null || value === undefined) return '-';
  if (key === 'status') return statusLabels[value] || value;
  if (key === 'priority') return priorityLabels[value] || value;
  if (key.includes('date')) {
    return formatDate(value);
  }
  return String(value);
};

// الحصول على وصف النشاط
const getActivityDescription = (activity: ActivityItem): string => {
  switch (activity.action) {
    case 'created':
      return 'أنشأ المهمة';
    case 'updated':
      return 'عدّل المهمة';
    case 'deleted':
      return 'حذف المهمة';
    case 'comment_added': {
      const commentAttachments = activity.new_values?.attachments_count || 0;
      if (commentAttachments > 0) {
        return `أضاف تعليقاً مع ${commentAttachments} مرفق`;
      }
      return 'أضاف تعليقاً';
    }
    case 'comment_deleted':
      return 'حذف تعليقاً';
    case 'attachment_added': {
      const attachmentsCount = activity.new_values?.attachments_count || 1;
      return `أضاف ${attachmentsCount} مرفق`;
    }
    case 'attachment_deleted':
      return `حذف مرفق: ${activity.old_values?.attachment_name || ''}`;
    case 'subtask_created':
      return `أضاف مهمة فرعية: ${activity.new_values?.subtask_title || ''}`;
    case 'subtask_updated':
      return `عدّل مهمة فرعية: ${activity.new_values?.subtask_title || ''}`;
    case 'subtask_deleted':
      return `حذف مهمة فرعية: ${activity.old_values?.subtask_title || ''}`;
    default:
      return activity.action;
  }
};

// الحصول على التغييرات المحددة
const getChanges = (activity: ActivityItem): { field: string; old: string; new: string }[] => {
  // دعم تحديثات المهمة العادية
  if (activity.action === 'updated' && activity.old_values && activity.new_values) {
    const changes: { field: string; old: string; new: string }[] = [];
    const newValues = activity.new_values;
    const oldValues = activity.old_values;

    Object.keys(newValues).forEach((key) => {
      // تجاهل الحقول التقنية
      if (['updated_at', 'created_at'].includes(key)) return;

      const oldVal = oldValues[key];
      const newVal = newValues[key];

      if (oldVal !== newVal) {
        changes.push({
          field: fieldLabels[key] || key,
          old: formatValue(key, oldVal),
          new: formatValue(key, newVal),
        });
      }
    });

    return changes;
  }

  // دعم تحديثات المهام الفرعية
  if (activity.action === 'subtask_updated' && activity.old_values?.changes && activity.new_values?.changes) {
    const changes: { field: string; old: string; new: string }[] = [];
    const oldChanges = activity.old_values.changes as Record<string, any>;
    const newChanges = activity.new_values.changes as Record<string, any>;

    Object.keys(newChanges).forEach((key) => {
      // تجاهل الحقول التقنية
      if (['updated_at', 'created_at'].includes(key)) return;

      const oldVal = oldChanges[key];
      const newVal = newChanges[key];

      if (oldVal !== newVal) {
        changes.push({
          field: fieldLabels[key] || key,
          old: formatValue(key, oldVal),
          new: formatValue(key, newVal),
        });
      }
    });

    return changes;
  }

  return [];
};

const TaskActivityLog: React.FC<TaskActivityLogProps> = ({
  taskId,
  maxItems = 5,
  showHeader = true,
  compact = false,
}) => {
  const [activities, setActivities] = useState<ActivityItem[]>([]);
  const [loading, setLoading] = useState(true);
  const [expanded, setExpanded] = useState(false);

  useEffect(() => {
    const fetchActivities = async () => {
      setLoading(true);
      try {
        const response = await tasksApi.getActivityLog(taskId, 50) as ActivityItem[];
        setActivities(response || []);
      } catch (error) {
        console.error('Failed to fetch activities:', error);
        setActivities([]);
      } finally {
        setLoading(false);
      }
    };

    if (taskId) {
      fetchActivities();
    }
  }, [taskId]);

  const displayedActivities = expanded ? activities : activities.slice(0, maxItems);
  const hasMore = activities.length > maxItems;

  if (loading) {
    return (
      <div className="flex items-center justify-center py-8">
        <IconLoader className="h-6 w-6 animate-spin text-[var(--text-tertiary)]" />
      </div>
    );
  }

  if (activities.length === 0) {
    return (
      <EmptyState
        icon={IconActivity}
        title="لا توجد نشاطات بعد"
        size="sm"
      />
    );
  }

  return (
    <div className="space-y-4">
      {showHeader && (
        <div className="flex items-center gap-2">
          <IconActivity className="h-5 w-5 text-[var(--accent-default)]" />
          <h4 className="font-semibold text-[var(--text-primary)]">سجل النشاطات</h4>
          <span className="text-xs text-[var(--text-tertiary)] bg-[var(--surface-muted)] px-2 py-0 rounded-full">
            {activities.length}
          </span>
        </div>
      )}

      <div className="relative">
        {/* Timeline line */}
        <div className="absolute right-[19px] top-2 bottom-2 w-0.5 bg-[var(--surface-muted)]" />

        <div className="space-y-4">
          {displayedActivities.map((activity) => {
            const Icon = activityIcons[activity.action] || activityIcons.default;
            const colors = activityColors[activity.action] || activityColors.default;
            const changes = getChanges(activity);

            return (
              <div
                key={activity.id}
                className={`relative flex gap-4 ${compact ? 'items-center' : 'items-start'}`}
              >
                {/* Icon */}
                <div className={`
                  relative z-10 flex-shrink-0
                  ${compact ? 'h-8 w-8' : 'h-10 w-10'}
                  rounded-xl ${colors.bg} flex items-center justify-center
                  ring-4 ring-[var(--surface-base)]
                `}>
                  <Icon className={`${compact ? 'h-4 w-4' : 'h-5 w-5'} ${colors.icon}`} />
                </div>

                {/* Content */}
                <div className={`flex-1 min-w-0 ${compact ? '' : 'pb-4'}`}>
                  <div className="flex items-start justify-between gap-2">
                    <div className="flex flex-col gap-1 min-w-0">
                      <div className="flex items-center gap-2">
                        <span className="text-sm font-medium text-[var(--text-primary)] truncate">
                          {activity.user?.name || 'النظام'}
                        </span>
                        <span className="text-sm text-[var(--text-secondary)]">
                          {getActivityDescription(activity)}
                        </span>
                      </div>
                    </div>
                    <div className="flex flex-col items-end gap-0 shrink-0">
                      {(() => {
                        const timeData = formatRelativeTime(activity.created_at);
                        return (
                          <>
                            <span className="text-xs text-[var(--text-tertiary)] whitespace-nowrap">
                              {timeData.relative}
                            </span>
                            {timeData.relative !== timeData.full && (
                              <span className="text-[11px] text-[var(--text-tertiary)] whitespace-nowrap">
                                {timeData.full}
                              </span>
                            )}
                          </>
                        );
                      })()}
                    </div>
                  </div>

                  {/* التغييرات التفصيلية */}
                  {changes.length > 0 && !compact && (
                    <div className="mt-2 space-y-1">
                      {changes.map((change, idx) => (
                        <div key={idx} className="flex items-center gap-2 text-xs">
                          <span className="text-[var(--text-tertiary)] min-w-[70px]">
                            {change.field}:
                          </span>
                          {change.old !== '-' && (
                            <span className="px-2 py-1 bg-[var(--status-danger-subtle)] text-[var(--status-danger)] rounded-md line-through">
                              {change.old}
                            </span>
                          )}
                          {change.old !== '-' && change.new !== '-' && (
                            <span className="text-[var(--text-tertiary)]">←</span>
                          )}
                          {change.new !== '-' && (
                            <span className="px-2 py-1 bg-[var(--status-success-subtle)] text-[var(--status-success)] rounded-md">
                              {change.new}
                            </span>
                          )}
                        </div>
                      ))}
                    </div>
                  )}
                </div>
              </div>
            );
          })}
        </div>
      </div>

      {/* Show more/less button */}
      {hasMore && (
        <button
          onClick={() => setExpanded(!expanded)}
          className="w-full flex items-center justify-center gap-2 py-2 text-sm text-[var(--text-secondary)] hover:text-[var(--accent-default)] transition-colors"
        >
          {expanded ? (
            <>
              <IconChevronUp className="h-4 w-4" />
              عرض أقل
            </>
          ) : (
            <>
              <IconChevronDown className="h-4 w-4" />
              عرض المزيد ({activities.length - maxItems})
            </>
          )}
        </button>
      )}
    </div>
  );
};

export default TaskActivityLog;
