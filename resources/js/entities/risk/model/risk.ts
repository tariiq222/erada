/**
 * Risk entity — domain model (enums + value types).
 */

// TS side of the enum-parity guard — these value lists MUST match the PHP enums
// in app/Modules/RiskManagement/Enums (RiskType, RiskStatus, RiskLevel,
// RiskResponseType). Phase 1's RiskEnumParityTest pins migrations ↔ PHP enums;
// the Vitest enum-parity test pins these arrays to the same canonical lists.
export const RISK_TYPE_VALUES = ["operational", "clinical", "financial", "technical", "compliance", "reputational"] as const;
export const RISK_STATUS_VALUES = ["open", "treating", "closed", "accepted"] as const;
export const RISK_LEVEL_VALUES = ["low", "medium", "high", "critical"] as const;
export const RISK_RESPONSE_TYPE_VALUES = ["avoid", "mitigate", "transfer", "accept"] as const;
export type RiskTypeValue = (typeof RISK_TYPE_VALUES)[number];
export type RiskStatusValue = (typeof RISK_STATUS_VALUES)[number];
export type RiskLevelValue = (typeof RISK_LEVEL_VALUES)[number];
export type RiskResponseTypeValue = (typeof RISK_RESPONSE_TYPE_VALUES)[number];

export interface RiskSettingOption {
  id: number | string;
  value: string | number;
  label: string;
  is_active: boolean;
  sort_order?: number | string | null;
}

export interface RiskSettings {
  risk_types: RiskSettingOption[];
  impact_types: RiskSettingOption[];
}

export interface RiskTypeSettingsPayload {
  value: string;
  label: string;
  is_active: boolean;
  sort_order: number;
}

export interface ImpactTypeSettingsPayload {
  value: string;
  label: string;
  is_active: boolean;
  sort_order: number;
}
