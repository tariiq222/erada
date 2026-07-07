import { OVR_STATUS_BADGE_VARIANT } from '@shared/lib/statusTokens';

export const severityLabels: Record<string, string> = {
  low: 'ovr.severity_low',
  medium: 'ovr.severity_medium',
  high: 'ovr.severity_high',
  critical: 'ovr.severity_critical',
};

export const severityColors: Record<string, 'success' | 'warning' | 'danger' | 'accent'> = {
  low: 'success',
  medium: 'warning',
  high: 'danger',
  critical: 'danger',
};

// SLA hints per severity level (treatment window)
export const severitySlaHints: Record<string, string> = {
  low: 'ovr.sla_low',
  medium: 'ovr.sla_medium',
  high: 'ovr.sla_high',
  critical: 'ovr.sla_critical',
};

// Contributing factors checklist (stored as string keys array)
export const CONTRIBUTING_FACTORS = [
  'communication',
  'staffing',
  'training',
  'environment',
  'equipment',
  'policies',
  'patientFactors',
  'teamwork',
  'leadership',
  'other',
] as const;

export const contributingFactorLabels: Record<string, string> = {
  communication: 'ovr.factor_communication',
  staffing: 'ovr.factor_staffing',
  training: 'ovr.factor_training',
  environment: 'ovr.factor_environment',
  equipment: 'ovr.factor_equipment',
  policies: 'ovr.factor_policies',
  patientFactors: 'ovr.factor_patient_factors',
  teamwork: 'ovr.factor_teamwork',
  leadership: 'ovr.factor_leadership',
  other: 'ovr.factor_other',
};

export const statusLabels: Record<string, string> = {
  draft: 'ovr.status_draft',
  new: 'ovr.status_new',
  under_review: 'ovr.status_under_review',
  pending_info: 'ovr.status_pending_info',
  in_progress: 'ovr.status_in_progress',
  resolved: 'ovr.status_resolved',
  closed: 'ovr.status_closed',
  rejected: 'ovr.status_rejected',
  archived: 'ovr.status_archived',
};

// مصدر واحد للحقيقة: @shared/lib/statusTokens
// نُعيد التصدير هنا للحفاظ على توافق المستهلكين (IncidentsTable,
// IncidentViewModal, AuditLogTab, PublicTrackReport, IncidentFormWizard).
// النوع المُعاد تصديره عريض لتفادي كسر فهرسة المستهلكين الذين يستخدمون `string`.
export const statusColors: Record<string,
  'default' | 'accent' | 'warning' | 'success' | 'danger' | 'info'
> = OVR_STATUS_BADGE_VARIANT as Record<string,
  'default' | 'accent' | 'warning' | 'success' | 'danger' | 'info'
>;
