import { describe, it, expect } from 'vitest';

import {
  RISK_TYPE_VALUES,
  RISK_STATUS_VALUES,
  RISK_LEVEL_VALUES,
  RISK_RESPONSE_TYPE_VALUES,
} from '@entities/risk';

// Canonical value lists — must match the PHP enums in
// app/Modules/RiskManagement/Enums (pinned there by Phase 1's RiskEnumParityTest).
describe('Risk enum parity (TS ↔ PHP)', () => {
  it('RISK_TYPE_VALUES matches the canonical risk type list', () => {
    expect([...RISK_TYPE_VALUES]).toEqual([
      'operational',
      'clinical',
      'financial',
      'technical',
      'compliance',
      'reputational',
    ]);
  });

  it('RISK_STATUS_VALUES matches the canonical risk status list', () => {
    expect([...RISK_STATUS_VALUES]).toEqual(['open', 'treating', 'closed', 'accepted']);
  });

  it('RISK_LEVEL_VALUES matches the canonical risk level list', () => {
    expect([...RISK_LEVEL_VALUES]).toEqual(['low', 'medium', 'high', 'critical']);
  });

  it('RISK_RESPONSE_TYPE_VALUES matches the canonical response type list', () => {
    expect([...RISK_RESPONSE_TYPE_VALUES]).toEqual(['avoid', 'mitigate', 'transfer', 'accept']);
  });
});
