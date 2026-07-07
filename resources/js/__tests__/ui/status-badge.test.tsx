import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import React from 'react';

vi.mock('react-i18next', () => {
  const ar = {
    'status.draft': 'مسودة', 'status.planning': 'تخطيط', 'status.in_progress': 'قيد التنفيذ',
    'status.on_hold': 'معلق', 'status.completed': 'مكتمل', 'status.cancelled': 'ملغى',
    'status.todo': 'للتنفيذ', 'status.in_review': 'قيد المراجعة', 'status.pending': 'معلق',
    'status.delayed': 'متأخر', 'status.completed_female': 'مكتملة', 'status.active': 'نشط',
    'priority.low': 'منخفضة', 'priority.medium': 'متوسطة', 'priority.high': 'عالية',
    'priority.urgent': 'عاجلة', 'priority.critical': 'حرجة', 'priority.normal': 'عادية',
  } as Record<string, string>;
  return {
    useTranslation: () => ({
      t: (key: string) => ar[key] ?? key,
      i18n: { changeLanguage: vi.fn(), language: 'ar' },
    }),
    Trans: ({ i18nKey }: { i18nKey: string }) => ar[i18nKey] ?? i18nKey,
    initReactI18next: { type: '3rdParty', init: vi.fn() },
  };
});

import {
  StatusBadge,
  getProjectStatusLabel,
  getTaskStatusLabel,
  getPriorityLabel,
  PROJECT_STATUS_MAP,
  TASK_STATUS_MAP,
  PRIORITY_MAP,
} from '@shared/ui/StatusBadge';

// Mock cn utility
vi.mock('@shared/lib/utils', () => ({
  cn: (...args: (string | undefined | null | false)[]) => args.filter(Boolean).join(' '),
}));

describe('StatusBadge Component', () => {
  describe('Project Status', () => {
    it('renders draft status', () => {
      render(<StatusBadge type="project" status="draft" />);
      expect(screen.getByText('مسودة')).toBeInTheDocument();
    });

    it('renders planning status', () => {
      render(<StatusBadge type="project" status="planning" />);
      expect(screen.getByText('تخطيط')).toBeInTheDocument();
    });

    it('renders in_progress status', () => {
      render(<StatusBadge type="project" status="in_progress" />);
      expect(screen.getByText('قيد التنفيذ')).toBeInTheDocument();
    });

    it('renders on_hold status', () => {
      render(<StatusBadge type="project" status="on_hold" />);
      expect(screen.getByText('معلق')).toBeInTheDocument();
    });

    it('renders completed status', () => {
      render(<StatusBadge type="project" status="completed" />);
      expect(screen.getByText('مكتمل')).toBeInTheDocument();
    });

    it('renders cancelled status', () => {
      render(<StatusBadge type="project" status="cancelled" />);
      expect(screen.getByText('ملغى')).toBeInTheDocument();
    });

    it('renders unknown status as-is', () => {
      render(<StatusBadge type="project" status="unknown_status" />);
      expect(screen.getByText('unknown_status')).toBeInTheDocument();
    });
  });

  describe('Task Status', () => {
    it('renders todo status', () => {
      render(<StatusBadge type="task" status="todo" />);
      expect(screen.getByText('للتنفيذ')).toBeInTheDocument();
    });

    it('renders in_progress status', () => {
      render(<StatusBadge type="task" status="in_progress" />);
      expect(screen.getByText('قيد التنفيذ')).toBeInTheDocument();
    });

    it('renders in_review status', () => {
      render(<StatusBadge type="task" status="in_review" />);
      expect(screen.getByText('قيد المراجعة')).toBeInTheDocument();
    });

    it('renders completed status', () => {
      render(<StatusBadge type="task" status="completed" />);
      expect(screen.getByText(/مكتمل/)).toBeInTheDocument();
    });
  });

  describe('Priority Badges', () => {
    it('renders low priority', () => {
      render(<StatusBadge type="priority" status="low" />);
      expect(screen.getByText('منخفضة')).toBeInTheDocument();
    });

    it('renders medium priority', () => {
      render(<StatusBadge type="priority" status="medium" />);
      expect(screen.getByText('متوسطة')).toBeInTheDocument();
    });

    it('renders high priority', () => {
      render(<StatusBadge type="priority" status="high" />);
      expect(screen.getByText('عالية')).toBeInTheDocument();
    });

    it('renders critical priority', () => {
      render(<StatusBadge type="priority" status="critical" />);
      expect(screen.getByText('حرجة')).toBeInTheDocument();
    });
  });

  describe('Custom Badges', () => {
    it('renders custom badge with primary color', () => {
      render(<StatusBadge type="custom" status="custom" label="مخصص" color="primary" />);
      expect(screen.getByText('مخصص')).toBeInTheDocument();
    });

    it('renders custom badge with success color', () => {
      render(<StatusBadge type="custom" status="custom" label="ناجح" color="success" />);
      expect(screen.getByText('ناجح')).toBeInTheDocument();
    });

    it('renders custom badge with warning color', () => {
      render(<StatusBadge type="custom" status="custom" label="تحذير" color="warning" />);
      expect(screen.getByText('تحذير')).toBeInTheDocument();
    });

    it('renders custom badge with danger color', () => {
      render(<StatusBadge type="custom" status="custom" label="خطر" color="danger" />);
      expect(screen.getByText('خطر')).toBeInTheDocument();
    });

    it('renders custom badge with info color', () => {
      render(<StatusBadge type="custom" status="custom" label="معلومات" color="info" />);
      expect(screen.getByText('معلومات')).toBeInTheDocument();
    });
  });

  describe('Size Variants', () => {
    it('renders small size', () => {
      render(<StatusBadge type="project" status="completed" size="sm" />);
      const badge = screen.getByText('مكتمل');
      // sm uses zero vertical padding; md uses py-1
      expect(badge.className).toContain('py-0');
      expect(badge.className).toContain('text-[11px]');
    });

    it('renders medium size by default', () => {
      render(<StatusBadge type="project" status="completed" />);
      const badge = screen.getByText('مكتمل');
      expect(badge.className).toContain('py-1');
      expect(badge.className).toContain('text-[11px]');
    });
  });

  describe('Dot Indicator', () => {
    it('shows dot when showDot is true', () => {
      render(<StatusBadge type="project" status="completed" showDot />);
      // The badge wrapper is itself rounded-full; the dot is the inner bg-current span.
      const badge = screen.getByText('مكتمل');
      expect(badge.querySelector('.bg-current')).toBeInTheDocument();
    });

    it('hides dot by default', () => {
      render(<StatusBadge type="project" status="completed" />);
      const badge = screen.getByText('مكتمل');
      expect(badge.querySelector('.bg-current')).not.toBeInTheDocument();
    });
  });

  describe('Custom ClassName', () => {
    it('applies custom className', () => {
      render(
        <StatusBadge type="project" status="completed" className="custom-class" />
      );
      const badge = screen.getByText('مكتمل');
      expect(badge.className).toContain('custom-class');
    });
  });
});

describe('Helper Functions', () => {
  const arTranslations: Record<string, string> = {
    'status.draft': 'مسودة', 'status.planning': 'تخطيط', 'status.in_progress': 'قيد التنفيذ',
    'status.completed': 'مكتمل', 'status.todo': 'للتنفيذ', 'status.completed_female': 'مكتملة',
    'priority.low': 'منخفضة', 'priority.medium': 'متوسطة', 'priority.high': 'عالية',
    'priority.critical': 'حرجة',
  };
  const t = (key: string) => arTranslations[key] ?? key;

  describe('getProjectStatusLabel', () => {
    it('returns correct label for draft', () => {
      expect(getProjectStatusLabel('draft', t)).toBe('مسودة');
    });

    it('returns correct label for planning', () => {
      expect(getProjectStatusLabel('planning', t)).toBe('تخطيط');
    });

    it('returns correct label for in_progress', () => {
      expect(getProjectStatusLabel('in_progress', t)).toBe('قيد التنفيذ');
    });

    it('returns correct label for completed', () => {
      expect(getProjectStatusLabel('completed', t)).toBe('مكتمل');
    });

    it('returns status as-is for unknown status', () => {
      expect(getProjectStatusLabel('unknown' as never)).toBe('unknown');
    });
  });

  describe('getTaskStatusLabel', () => {
    it('returns correct label for todo', () => {
      expect(getTaskStatusLabel('todo', t)).toBe('للتنفيذ');
    });

    it('returns correct label for in_progress', () => {
      expect(getTaskStatusLabel('in_progress', t)).toBe('قيد التنفيذ');
    });

    it('returns correct label for completed', () => {
      expect(getTaskStatusLabel('completed', t)).toBe('مكتملة');
    });
  });

  describe('getPriorityLabel', () => {
    it('returns correct label for low', () => {
      expect(getPriorityLabel('low', t)).toBe('منخفضة');
    });

    it('returns correct label for medium', () => {
      expect(getPriorityLabel('medium', t)).toBe('متوسطة');
    });

    it('returns correct label for high', () => {
      expect(getPriorityLabel('high', t)).toBe('عالية');
    });

    it('returns correct label for critical', () => {
      expect(getPriorityLabel('critical', t)).toBe('حرجة');
    });
  });
});

describe('Exported Maps', () => {
  describe('PROJECT_STATUS_MAP', () => {
    it('contains all project statuses', () => {
      expect(PROJECT_STATUS_MAP).toHaveProperty('draft');
      expect(PROJECT_STATUS_MAP).toHaveProperty('planning');
      expect(PROJECT_STATUS_MAP).toHaveProperty('in_progress');
      expect(PROJECT_STATUS_MAP).toHaveProperty('on_hold');
      expect(PROJECT_STATUS_MAP).toHaveProperty('completed');
      expect(PROJECT_STATUS_MAP).toHaveProperty('cancelled');
    });

    it('each status has label and classes', () => {
      Object.values(PROJECT_STATUS_MAP).forEach((status) => {
        expect(status).toHaveProperty('key');
        expect(status).toHaveProperty('classes');
      });
    });
  });

  describe('TASK_STATUS_MAP', () => {
    it('contains all task statuses', () => {
      expect(TASK_STATUS_MAP).toHaveProperty('todo');
      expect(TASK_STATUS_MAP).toHaveProperty('in_progress');
      expect(TASK_STATUS_MAP).toHaveProperty('in_review');
      expect(TASK_STATUS_MAP).toHaveProperty('completed');
    });
  });

  describe('PRIORITY_MAP', () => {
    it('contains all priorities', () => {
      expect(PRIORITY_MAP).toHaveProperty('low');
      expect(PRIORITY_MAP).toHaveProperty('medium');
      expect(PRIORITY_MAP).toHaveProperty('high');
      expect(PRIORITY_MAP).toHaveProperty('critical');
    });
  });
});

describe('Accessibility', () => {
  it('badge is accessible as inline element', () => {
    render(<StatusBadge type="project" status="completed" />);
    const badge = screen.getByText('مكتمل');
    expect(badge).toBeInTheDocument();
    expect(badge.tagName).toBe('SPAN');
  });

  it('contains visible text content', () => {
    render(<StatusBadge type="project" status="completed" />);
    const badge = screen.getByText('مكتمل');
    expect(badge).toBeVisible();
  });
});
