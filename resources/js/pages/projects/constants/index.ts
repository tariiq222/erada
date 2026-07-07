import React from 'react';
import {IconCircle, IconClock, IconEye, IconCircleCheck, IconUser} from '@tabler/icons-react';
import type { TaskStatus } from '../types';

// Project Status Labels & Variants
export const statusLabels: Record<string, string> = {
  draft: 'مسودة',
  planning: 'تخطيط',
  in_progress: 'قيد التنفيذ',
  on_hold: 'معلق',
  completed: 'مكتمل',
  cancelled: 'ملغى',
};

export const statusVariants: Record<string, 'default' | 'accent' | 'success' | 'warning' | 'danger'> = {
  draft: 'default',
  planning: 'accent',
  in_progress: 'accent',
  on_hold: 'warning',
  completed: 'success',
  cancelled: 'danger',
};

// Task Status Labels & Icons
export const taskStatusLabels: Record<string, string> = {
  todo: 'للتنفيذ',
  in_progress: 'قيد التنفيذ',
  in_review: 'قيد المراجعة',
  completed: 'مكتملة',
};

export const taskStatusIcons: Record<string, React.FC<{ className?: string }>> = {
  todo: IconCircle,
  in_progress: IconClock,
  in_review: IconEye,
  completed: IconCircleCheck,
};

export const taskStatusColors: Record<string, string> = {
  todo: 'text-[var(--text-tertiary)] bg-[var(--surface-muted)]',
  in_progress: 'text-[var(--accent-default)] bg-[var(--accent-subtle)]',
  in_review: 'text-[var(--status-warning-text)] bg-[var(--status-warning-subtle)]',
  completed: 'text-[var(--status-success-text)] bg-[var(--status-success-subtle)]',
};

// Time Indicator Colors
export const timeIndicatorColors: Record<string, { bg: string; fill: string; text: string }> = {
  normal: { bg: 'bg-[var(--surface-muted)]', fill: 'bg-[var(--accent-default)]', text: 'text-[var(--text-secondary)]' },
  warning: { bg: 'bg-[var(--status-warning-subtle)]', fill: 'bg-[var(--status-warning)]', text: 'text-[var(--status-warning-text)]' },
  urgent: { bg: 'bg-[var(--status-warning-subtle)]', fill: 'bg-[var(--status-warning)]', text: 'text-[var(--status-warning-text)]' },
  overdue: { bg: 'bg-[var(--status-danger-subtle)]', fill: 'bg-[var(--status-danger)]', text: 'text-[var(--status-danger-text)]' },
  completed: { bg: 'bg-[var(--status-success-subtle)]', fill: 'bg-[var(--status-success)]', text: 'text-[var(--status-success-text)]' },
};

// Kanban Column Styles
export const kanbanColumnStyles: Record<string, { bg: string; border: string; headerBg: string; headerText: string; progressBg: string }> = {
  todo: {
    bg: 'bg-[var(--surface-subtle)]/80',
    border: 'border-[var(--border-default)]',
    headerBg: 'bg-[var(--surface-muted)]',
    headerText: 'text-[var(--text-secondary)]',
    progressBg: 'bg-[var(--text-tertiary)]',
  },
  pending: {
    bg: 'bg-[var(--surface-subtle)]/80',
    border: 'border-[var(--border-default)]',
    headerBg: 'bg-[var(--surface-muted)]',
    headerText: 'text-[var(--text-secondary)]',
    progressBg: 'bg-[var(--text-tertiary)]',
  },
  in_progress: {
    bg: 'bg-[var(--accent-subtle)]/50',
    border: 'border-[var(--accent-default)]',
    headerBg: 'bg-[var(--accent-subtle)]',
    headerText: 'text-[var(--accent-default)]',
    progressBg: 'bg-[var(--accent-default)]',
  },
  in_review: {
    bg: 'bg-[var(--status-warning-subtle)]/50',
    border: 'border-[var(--status-warning)]',
    headerBg: 'bg-[var(--status-warning-subtle)]',
    headerText: 'text-[var(--status-warning-text)]',
    progressBg: 'bg-[var(--status-warning)]',
  },
  completed: {
    bg: 'bg-[var(--status-success-subtle)]/50',
    border: 'border-[var(--status-success)]',
    headerBg: 'bg-[var(--status-success-subtle)]',
    headerText: 'text-[var(--status-success-text)]',
    progressBg: 'bg-[var(--status-success)]',
  },
};

// Status Order for Transitions
export const STATUS_ORDER = ['todo', 'in_progress', 'in_review', 'completed'] as const;

// Priority Labels & Colors
export const priorityLabels: Record<string, string> = {
  low: 'منخفضة',
  medium: 'متوسطة',
  high: 'عالية',
  urgent: 'عاجلة',
};

export const priorityColors: Record<string, string> = {
  low: 'text-[var(--text-tertiary)]',
  medium: 'text-[var(--accent-default)]',
  high: 'text-[var(--status-warning-text)]',
  urgent: 'text-[var(--status-danger-text)]',
};

// Role Icons - أدوار المشروع الموحدة (manager/member/viewer)
export const roleIcons: Record<string, React.FC<{ className?: string }>> = {
  manager: IconUser,
  member: IconUser,
  viewer: IconEye,
  // legacy alias (بيانات قديمة معروضة فقط)
  team_member: IconUser,
  default: IconUser,
};

// Role Options - أدوار أعضاء الفريق الموحدة
export const roleOptions = [
  { value: 'manager', label: 'مدير' },
  { value: 'member', label: 'عضو' },
  { value: 'viewer', label: 'مشاهد' },
];

// Role Labels - للعرض
export const roleLabels: Record<string, string> = {
  manager: 'مدير المشروع',
  member: 'عضو',
  viewer: 'مشاهد',
  // legacy alias (بيانات قديمة معروضة فقط)
  team_member: 'عضو',
};

// Stakeholder Role Options
export const stakeholderRoleOptions = [
  { value: 'end_user', label: 'مستخدم نهائي' },
  { value: 'implementer', label: 'جهة منفذة' },
  { value: 'consultant', label: 'مستشار' },
  { value: 'governance', label: 'جهة رقابية' },
  { value: 'operations', label: 'داعم تشغيلي' },
  { value: 'influencer', label: 'صاحب تأثير' },
  { value: 'other', label: 'أخرى' },
];

// Stakeholder Role Labels (للعرض)
export const stakeholderRoleLabels: Record<string, string> = {
  end_user: 'مستخدم نهائي',
  implementer: 'جهة منفذة',
  consultant: 'مستشار',
  governance: 'جهة رقابية',
  operations: 'داعم تشغيلي',
  influencer: 'صاحب تأثير',
  other: 'أخرى',
};

// Stakeholder Influence Labels (للعرض)
export const stakeholderInfluenceLabels: Record<string, string> = {
  low: 'منخفض',
  medium: 'متوسط',
  high: 'عالي',
};

// Probability Options for Risks
export const probabilityOptions = [
  { value: 'low', label: 'منخفضة', color: 'bg-[var(--status-success-subtle)] text-[var(--status-success-text)] border-[var(--status-success)]' },
  { value: 'medium', label: 'متوسطة', color: 'bg-[var(--status-warning-subtle)] text-[var(--status-warning-text)] border-[var(--status-warning)]' },
  { value: 'high', label: 'عالية', color: 'bg-[var(--status-danger-subtle)] text-[var(--status-danger-text)] border-[var(--status-danger)]' },
];

// Impact Options for Risks
export const impactOptions = [
  { value: 'low', label: 'منخفض', color: 'bg-[var(--status-success-subtle)] text-[var(--status-success-text)] border-[var(--status-success)]' },
  { value: 'medium', label: 'متوسط', color: 'bg-[var(--status-warning-subtle)] text-[var(--status-warning-text)] border-[var(--status-warning)]' },
  { value: 'high', label: 'عالي', color: 'bg-[var(--status-danger-subtle)] text-[var(--status-danger-text)] border-[var(--status-danger)]' },
];

// Risk Status Options
export const riskStatusOptions = [
  { value: 'open', label: 'مفتوح' },
  { value: 'mitigated', label: 'مُخفف' },
  { value: 'closed', label: 'مغلق' },
];

// Status Transition Rules
export const getStatusTransitionRules = (
  userRole: 'super_admin' | 'project_manager' | 'member',
  currentStatus: TaskStatus,
  newStatus: TaskStatus
): { allowed: boolean; requiresConfirmation: boolean; confirmationMessage: string } => {
  const currentIndex = STATUS_ORDER.indexOf(currentStatus);
  const newIndex = STATUS_ORDER.indexOf(newStatus);

  // النقل إلى "قيد التنفيذ" لا يحتاج تأكيد - يتم مباشرة
  if (newStatus === 'in_progress') {
    // التحقق من الصلاحيات أولاً
    if (currentStatus === 'in_review' && userRole === 'member') {
      return {
        allowed: false,
        requiresConfirmation: false,
        confirmationMessage: 'لا يمكن إعادة المهمة من المراجعة.',
      };
    }
    return { allowed: true, requiresConfirmation: false, confirmationMessage: '' };
  }

  // مدير النظام له صلاحيات كاملة
  if (userRole === 'super_admin') {
    if (newStatus === 'completed') {
      return {
        allowed: true,
        requiresConfirmation: true,
        confirmationMessage: 'هل أنت متأكد من إغلاق هذه المهمة نهائياً؟ لن يمكن إعادة فتحها.',
      };
    }
    if (newStatus === 'in_review') {
      return {
        allowed: true,
        requiresConfirmation: true,
        confirmationMessage: 'هل تريد إرسال المهمة للمراجعة؟',
      };
    }
    return { allowed: true, requiresConfirmation: false, confirmationMessage: '' };
  }

  // مدير المشروع يمكنه نقل المهمة لأي حالة
  if (userRole === 'project_manager') {
    if (newStatus === 'completed') {
      return {
        allowed: true,
        requiresConfirmation: true,
        confirmationMessage: 'هل أنت متأكد من إغلاق هذه المهمة نهائياً؟ لن يمكن إعادة فتحها.',
      };
    }
    if (newStatus === 'in_review') {
      return {
        allowed: true,
        requiresConfirmation: true,
        confirmationMessage: 'هل تريد إرسال المهمة للمراجعة؟',
      };
    }
    return { allowed: true, requiresConfirmation: false, confirmationMessage: '' };
  }

  // الأعضاء العاديين
  // لا يمكن نقل المهمة إلى مكتمل
  if (newStatus === 'completed') {
    return {
      allowed: false,
      requiresConfirmation: false,
      confirmationMessage: 'فقط مدير المشروع يمكنه إكمال المهمة.',
    };
  }

  // لا يمكن الرجوع للخلف من قيد المراجعة
  if (currentStatus === 'in_review' && newIndex < currentIndex) {
    return {
      allowed: false,
      requiresConfirmation: false,
      confirmationMessage: 'لا يمكن إعادة المهمة للحالات السابقة بعد وصولها للمراجعة.',
    };
  }

  // النقل إلى قيد المراجعة يحتاج تأكيد وتعليق
  if (newStatus === 'in_review') {
    return {
      allowed: true,
      requiresConfirmation: true,
      confirmationMessage: 'هل تريد إرسال المهمة للمراجعة؟',
    };
  }

  // يمكن التقدم للأمام
  if (newIndex > currentIndex) {
    return { allowed: true, requiresConfirmation: false, confirmationMessage: '' };
  }

  // الرجوع من in_progress إلى todo
  if (currentStatus === 'in_progress' && newStatus === 'todo') {
    return { allowed: true, requiresConfirmation: false, confirmationMessage: '' };
  }

  return {
    allowed: false,
    requiresConfirmation: false,
    confirmationMessage: 'لا يمكن تغيير الحالة بهذه الطريقة.',
  };
};
