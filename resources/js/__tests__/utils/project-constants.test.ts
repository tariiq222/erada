import { describe, it, expect } from 'vitest';

import {
  statusLabels,
  statusVariants,
  taskStatusLabels,
  taskStatusColors,
  timeIndicatorColors,
  kanbanColumnStyles,
  STATUS_ORDER,
  priorityLabels,
  priorityColors,
  roleOptions,
  stakeholderRoleOptions,
  stakeholderRoleLabels,
  probabilityOptions,
  impactOptions,
  riskStatusOptions,
  getStatusTransitionRules,
} from '@pages/projects/constants';

describe('statusLabels', () => {
  it('has draft label', () => {
    expect(statusLabels.draft).toBe('مسودة');
  });

  it('has planning label', () => {
    expect(statusLabels.planning).toBe('تخطيط');
  });

  it('has in_progress label', () => {
    expect(statusLabels.in_progress).toBe('قيد التنفيذ');
  });

  it('has on_hold label', () => {
    expect(statusLabels.on_hold).toBe('معلق');
  });

  it('has completed label', () => {
    expect(statusLabels.completed).toBe('مكتمل');
  });

  it('has cancelled label', () => {
    expect(statusLabels.cancelled).toBe('ملغى');
  });
});

describe('statusVariants', () => {
  it('draft is default variant', () => {
    expect(statusVariants.draft).toBe('default');
  });

  it('in_progress is accent variant', () => {
    expect(statusVariants.in_progress).toBe('accent');
  });

  it('completed is success variant', () => {
    expect(statusVariants.completed).toBe('success');
  });

  it('on_hold is warning variant', () => {
    expect(statusVariants.on_hold).toBe('warning');
  });

  it('cancelled is danger variant', () => {
    expect(statusVariants.cancelled).toBe('danger');
  });
});

describe('taskStatusLabels', () => {
  it('has todo label', () => {
    expect(taskStatusLabels.todo).toBe('للتنفيذ');
  });

  it('has in_progress label', () => {
    expect(taskStatusLabels.in_progress).toBe('قيد التنفيذ');
  });

  it('has in_review label', () => {
    expect(taskStatusLabels.in_review).toBe('قيد المراجعة');
  });

  it('has completed label', () => {
    expect(taskStatusLabels.completed).toBe('مكتملة');
  });
});

describe('taskStatusColors', () => {
  it('has todo colors', () => {
    expect(taskStatusColors.todo).toContain('var(--');
  });

  it('has in_progress colors', () => {
    expect(taskStatusColors.in_progress).toContain('var(--');
  });

  it('has in_review colors', () => {
    expect(taskStatusColors.in_review).toContain('var(--');
  });

  it('has completed colors', () => {
    expect(taskStatusColors.completed).toContain('var(--');
  });
});

describe('timeIndicatorColors', () => {
  it('has normal color set', () => {
    expect(timeIndicatorColors.normal).toHaveProperty('bg');
    expect(timeIndicatorColors.normal).toHaveProperty('fill');
    expect(timeIndicatorColors.normal).toHaveProperty('text');
  });

  it('has warning color set', () => {
    expect(timeIndicatorColors.warning.fill).toContain('var(--');
  });

  it('has urgent color set', () => {
    expect(timeIndicatorColors.urgent.fill).toContain('var(--');
  });

  it('has overdue color set', () => {
    expect(timeIndicatorColors.overdue.fill).toContain('var(--');
  });

  it('has completed color set', () => {
    expect(timeIndicatorColors.completed.fill).toContain('var(--');
  });
});

describe('kanbanColumnStyles', () => {
  it('has todo column style', () => {
    expect(kanbanColumnStyles.todo).toHaveProperty('bg');
    expect(kanbanColumnStyles.todo).toHaveProperty('border');
    expect(kanbanColumnStyles.todo).toHaveProperty('headerBg');
  });

  it('has pending column style', () => {
    expect(kanbanColumnStyles.pending).toHaveProperty('bg');
  });

  it('has in_progress column style', () => {
    expect(kanbanColumnStyles.in_progress.headerBg).toContain('var(--');
  });

  it('has in_review column style', () => {
    expect(kanbanColumnStyles.in_review.headerBg).toContain('var(--');
  });

  it('has completed column style', () => {
    expect(kanbanColumnStyles.completed.headerBg).toContain('var(--');
  });
});

describe('STATUS_ORDER', () => {
  it('has 4 statuses', () => {
    expect(STATUS_ORDER.length).toBe(4);
  });

  it('starts with todo', () => {
    expect(STATUS_ORDER[0]).toBe('todo');
  });

  it('ends with completed', () => {
    expect(STATUS_ORDER[3]).toBe('completed');
  });

  it('has correct order', () => {
    expect(STATUS_ORDER).toEqual(['todo', 'in_progress', 'in_review', 'completed']);
  });
});

describe('priorityLabels', () => {
  it('has low priority', () => {
    expect(priorityLabels.low).toBe('منخفضة');
  });

  it('has medium priority', () => {
    expect(priorityLabels.medium).toBe('متوسطة');
  });

  it('has high priority', () => {
    expect(priorityLabels.high).toBe('عالية');
  });

  it('has urgent priority', () => {
    expect(priorityLabels.urgent).toBe('عاجلة');
  });
});

describe('priorityColors', () => {
  it('has low color', () => {
    expect(priorityColors.low).toContain('var(--');
  });

  it('has high color', () => {
    expect(priorityColors.high).toContain('var(--');
  });

  it('has urgent color', () => {
    expect(priorityColors.urgent).toContain('var(--');
  });
});

describe('roleOptions', () => {
  it('has 3 role options (RBAC: manager/member/viewer)', () => {
    expect(roleOptions.length).toBe(3);
  });

  it('includes manager, member, and viewer roles with correct values and labels', () => {
    expect(roleOptions.some(r => r.value === 'manager')).toBe(true);
    expect(roleOptions.some(r => r.value === 'member')).toBe(true);
    expect(roleOptions.some(r => r.value === 'viewer')).toBe(true);
    expect(roleOptions.some(r => r.label === 'مدير')).toBe(true);
  });
});

describe('stakeholderRoleOptions', () => {
  it('has 7 options', () => {
    expect(stakeholderRoleOptions.length).toBe(7);
  });

  it('includes end_user', () => {
    expect(stakeholderRoleOptions.some(r => r.value === 'end_user')).toBe(true);
  });

  it('includes consultant', () => {
    expect(stakeholderRoleOptions.some(r => r.value === 'consultant')).toBe(true);
  });
});

describe('stakeholderRoleLabels', () => {
  it('has end_user label', () => {
    expect(stakeholderRoleLabels.end_user).toBe('مستخدم نهائي');
  });

  it('has consultant label', () => {
    expect(stakeholderRoleLabels.consultant).toBe('مستشار');
  });

  it('has other label', () => {
    expect(stakeholderRoleLabels.other).toBe('أخرى');
  });
});

describe('probabilityOptions', () => {
  it('has 3 options', () => {
    expect(probabilityOptions.length).toBe(3);
  });

  it('has low option with green color', () => {
    const low = probabilityOptions.find(o => o.value === 'low');
    expect(low?.color).toContain('var(--');
  });

  it('has high option with red color', () => {
    const high = probabilityOptions.find(o => o.value === 'high');
    expect(high?.color).toContain('var(--');
  });
});

describe('impactOptions', () => {
  it('has 3 options', () => {
    expect(impactOptions.length).toBe(3);
  });

  it('has medium option with amber color', () => {
    const medium = impactOptions.find(o => o.value === 'medium');
    expect(medium?.color).toContain('var(--');
  });
});

describe('riskStatusOptions', () => {
  it('has 3 options', () => {
    expect(riskStatusOptions.length).toBe(3);
  });

  it('includes open status', () => {
    expect(riskStatusOptions.some(o => o.value === 'open')).toBe(true);
  });

  it('includes mitigated status', () => {
    expect(riskStatusOptions.some(o => o.value === 'mitigated')).toBe(true);
  });

  it('includes closed status', () => {
    expect(riskStatusOptions.some(o => o.value === 'closed')).toBe(true);
  });
});

describe('getStatusTransitionRules - Super Admin', () => {
  it('allows moving to in_progress', () => {
    const result = getStatusTransitionRules('super_admin', 'todo', 'in_progress');
    expect(result.allowed).toBe(true);
    expect(result.requiresConfirmation).toBe(false);
  });

  it('allows moving to completed with confirmation', () => {
    const result = getStatusTransitionRules('super_admin', 'in_review', 'completed');
    expect(result.allowed).toBe(true);
    expect(result.requiresConfirmation).toBe(true);
  });

  it('allows moving to in_review with confirmation', () => {
    const result = getStatusTransitionRules('super_admin', 'in_progress', 'in_review');
    expect(result.allowed).toBe(true);
    expect(result.requiresConfirmation).toBe(true);
  });

  it('allows moving backward', () => {
    const result = getStatusTransitionRules('super_admin', 'in_progress', 'todo');
    expect(result.allowed).toBe(true);
  });
});

describe('getStatusTransitionRules - Project Manager', () => {
  it('allows moving to completed with confirmation', () => {
    const result = getStatusTransitionRules('project_manager', 'in_review', 'completed');
    expect(result.allowed).toBe(true);
    expect(result.requiresConfirmation).toBe(true);
  });

  it('allows moving to in_review with confirmation', () => {
    const result = getStatusTransitionRules('project_manager', 'in_progress', 'in_review');
    expect(result.allowed).toBe(true);
    expect(result.requiresConfirmation).toBe(true);
  });

  it('allows moving forward without confirmation', () => {
    const result = getStatusTransitionRules('project_manager', 'todo', 'in_progress');
    expect(result.allowed).toBe(true);
    expect(result.requiresConfirmation).toBe(false);
  });
});

describe('getStatusTransitionRules - Member', () => {
  it('does not allow moving to completed', () => {
    const result = getStatusTransitionRules('member', 'in_review', 'completed');
    expect(result.allowed).toBe(false);
    expect(result.confirmationMessage).toContain('مدير المشروع');
  });

  it('does not allow moving back from in_review', () => {
    const result = getStatusTransitionRules('member', 'in_review', 'in_progress');
    expect(result.allowed).toBe(false);
  });

  it('allows moving to in_review with confirmation', () => {
    const result = getStatusTransitionRules('member', 'in_progress', 'in_review');
    expect(result.allowed).toBe(true);
    expect(result.requiresConfirmation).toBe(true);
  });

  it('allows moving forward from todo to in_progress', () => {
    const result = getStatusTransitionRules('member', 'todo', 'in_progress');
    expect(result.allowed).toBe(true);
  });

  it('allows moving from in_progress to todo', () => {
    const result = getStatusTransitionRules('member', 'in_progress', 'todo');
    expect(result.allowed).toBe(true);
  });
});
