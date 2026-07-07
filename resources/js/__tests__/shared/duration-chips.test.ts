import { describe, it, expect } from 'vitest';
import { shiftDate } from '@shared/ui/DurationChips';

describe('shiftDate', () => {
  it('adds days within the same month', () => {
    expect(shiftDate('2026-06-10', { labelKey: '', days: 7 })).toBe('2026-06-17');
  });

  it('rolls over month and year boundaries on day math', () => {
    expect(shiftDate('2026-12-28', { labelKey: '', days: 14 })).toBe('2027-01-11');
  });

  it('adds calendar months', () => {
    expect(shiftDate('2026-01-15', { labelKey: '', months: 3 })).toBe('2026-04-15');
    expect(shiftDate('2026-06-26', { labelKey: '', months: 12 })).toBe('2027-06-26');
  });

  it('is timezone-stable (no UTC drift off the picked day)', () => {
    expect(shiftDate('2026-03-01', { labelKey: '', days: 0 })).toBe('2026-03-01');
  });
});
