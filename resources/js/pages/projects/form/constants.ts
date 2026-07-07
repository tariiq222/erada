import type { DeliverableItem, KpiInput, MilestoneItem, RiskItem, StakeholderItem, TaskItem, TeamMemberItem } from './types';

export const statusOptions = [
  { value: 'draft', labelKey: 'status.draft' },
  { value: 'planning', labelKey: 'status.planning' },
  { value: 'in_progress', labelKey: 'status.in_progress' },
  { value: 'on_hold', labelKey: 'status.on_hold' },
  { value: 'completed', labelKey: 'status.completed' },
  { value: 'cancelled', labelKey: 'status.cancelled' },
];

// Backend priority enum includes critical.
export const priorityOptions = [
  { value: 'low', labelKey: 'priority.low' },
  { value: 'medium', labelKey: 'priority.medium' },
  { value: 'high', labelKey: 'priority.high' },
  { value: 'urgent', labelKey: 'priority.urgent' },
  { value: 'critical', labelKey: 'priority.critical' },
];

// Backend impact enum is low|medium|high (no critical).
export const impactOptions = [
  { value: 'low', labelKey: 'projects.impact_low' },
  { value: 'medium', labelKey: 'projects.impact_medium' },
  { value: 'high', labelKey: 'projects.impact_high' },
];

export const probabilityOptions = [
  { value: 'low', labelKey: 'priority.low' },
  { value: 'medium', labelKey: 'priority.medium' },
  { value: 'high', labelKey: 'priority.high' },
];

export const influenceOptions = [
  { value: 'low', labelKey: 'projects.impact_low' },
  { value: 'medium', labelKey: 'projects.impact_medium' },
  { value: 'high', labelKey: 'projects.impact_high' },
];

export const stakeholderRoleOptions = [
  { value: 'end_user', labelKey: 'projects.stakeholder_end_user' },
  { value: 'implementer', labelKey: 'projects.stakeholder_implementer' },
  { value: 'consultant', labelKey: 'projects.stakeholder_consultant' },
  { value: 'governance', labelKey: 'projects.stakeholder_governance' },
  { value: 'operations', labelKey: 'projects.stakeholder_operations' },
  { value: 'influencer', labelKey: 'projects.stakeholder_influencer' },
  { value: 'other', labelKey: 'projects.stakeholder_other' },
];

export const emptyDeliverable: DeliverableItem = { name: '' };

export const emptyMilestone: MilestoneItem = {
  name: '',
  start_date: '',
  due_date: '',
  description: '',
  deliverables: [{ ...emptyDeliverable }],
};

export const emptyRisk: RiskItem = {
  description: '',
  impact: 'medium',
  probability: 'medium',
  mitigation: '',
};

export const emptyKpi: KpiInput = {
  name: '',
  baseline: '',
  target: '',
  unit: '',
  measurement_method: '',
};

export const emptyStakeholder: StakeholderItem = {
  user_id: undefined,
  name: '',
  role: '',
  contact: '',
  influence: 'medium',
};

export const teamRoleOptions = [
  { value: 'developer', labelKey: 'projects.role_developer' },
  { value: 'analyst', labelKey: 'projects.role_analyst' },
  { value: 'designer', labelKey: 'projects.role_designer' },
  { value: 'tester', labelKey: 'projects.role_tester' },
  { value: 'team_lead', labelKey: 'projects.role_team_lead' },
  { value: 'member', labelKey: 'projects.role_member' },
];

export const emptyTeamMember: TeamMemberItem = {
  user_id: undefined,
  name: '',
  role: '',
};

// Backend priority enum includes critical.
export const taskPriorityOptions = [
  { value: 'low', labelKey: 'priority.low' },
  { value: 'medium', labelKey: 'priority.medium' },
  { value: 'high', labelKey: 'priority.high' },
  { value: 'urgent', labelKey: 'priority.urgent' },
  { value: 'critical', labelKey: 'priority.critical' },
];

export const emptyTask: TaskItem = {
  name: '',
  description: '',
  milestone_index: undefined,
  assigned_to: undefined,
  assigned_to_name: '',
  priority: 'medium',
  start_date: '',
  due_date: '',
};
