import { describe, it, expect } from 'vitest';
import { cn, formatDate, formatDateShort, formatDateFull } from '@shared/lib/utils';

describe('cn (className merger)', () => {
  it('merges class names', () => {
    const result = cn('class1', 'class2');
    expect(result).toBe('class1 class2');
  });

  it('handles conditional classes', () => {
    const isActive = true;
    const result = cn('base', isActive && 'active');
    expect(result).toBe('base active');
  });

  it('handles false conditional classes', () => {
    const isActive = false;
    const result = cn('base', isActive && 'active');
    expect(result).toBe('base');
  });

  it('handles arrays of classes', () => {
    const result = cn(['class1', 'class2']);
    expect(result).toBe('class1 class2');
  });

  it('handles object notation', () => {
    const result = cn({ active: true, disabled: false });
    expect(result).toBe('active');
  });

  it('merges tailwind classes properly', () => {
    // tw-merge should override conflicting classes
    const result = cn('p-4', 'p-2');
    expect(result).toBe('p-2');
  });

  it('handles complex combinations', () => {
    const result = cn(
      'base',
      ['array-class'],
      { conditional: true },
      undefined,
      null
    );
    expect(result).toContain('base');
    expect(result).toContain('array-class');
    expect(result).toContain('conditional');
  });

  it('handles empty inputs', () => {
    const result = cn();
    expect(result).toBe('');
  });

  it('handles undefined and null', () => {
    const result = cn(undefined, null, 'valid');
    expect(result).toBe('valid');
  });
});

describe('formatDate', () => {
  it('formats Date object correctly', () => {
    const date = new Date('2024-01-15');
    const result = formatDate(date);
    expect(result).toBe('15/01/2024');
  });

  it('formats date string correctly', () => {
    const result = formatDate('2024-01-15');
    expect(result).toBe('15/01/2024');
  });

  it('uses custom options', () => {
    const date = new Date('2024-01-15');
    const result = formatDate(date, { year: 'numeric', month: 'long' });
    expect(result).toContain('2024');
    expect(result).toContain('January');
  });

  it('handles different date formats', () => {
    const result = formatDate('2024-12-25');
    expect(result).toBe('25/12/2024');
  });
});

describe('formatDateShort', () => {
  it('formats Date object correctly', () => {
    const date = new Date('2024-01-15');
    const result = formatDateShort(date);
    expect(result).toBe('15 Jan');
  });

  it('formats date string correctly', () => {
    const result = formatDateShort('2024-06-20');
    expect(result).toBe('20 Jun');
  });

  it('handles different months', () => {
    expect(formatDateShort('2024-12-25')).toBe('25 Dec');
    expect(formatDateShort('2024-03-10')).toBe('10 Mar');
  });
});

describe('formatDateFull', () => {
  it('formats Date object with weekday', () => {
    const date = new Date('2024-01-15');
    const result = formatDateFull(date);
    expect(result).toContain('Monday');
    expect(result).toContain('January');
    expect(result).toContain('2024');
  });

  it('formats date string correctly', () => {
    const result = formatDateFull('2024-06-20');
    expect(result).toContain('Thursday');
    expect(result).toContain('June');
    expect(result).toContain('2024');
  });
});
