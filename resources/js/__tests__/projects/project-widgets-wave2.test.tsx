import React from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

// The report card now sources its status/priority pills from the shared
// StatusBadge and its section labels from i18n keys. Translate the keys this
// suite asserts on; unknown keys fall back to the key itself.
vi.mock('react-i18next', () => {
  const map: Record<string, string> = {
    'priority.critical': 'حرجة',
    'projects.report.print_export': 'طباعة / تصدير PDF',
    'projects.report.progress': 'الإنجاز',
    'projects.report.no_milestones': 'لا توجد مراحل',
    'projects.report.no_kpis': 'لا توجد مؤشرات',
    'projects.report.no_risks': 'لا توجد مخاطر',
  };
  return {
    useTranslation: () => ({
      t: (key: string, opts?: Record<string, unknown>) => {
        const value = map[key] ?? key;
        if (opts && typeof opts.count === 'number') {
          return value.replace('{{count}}', String(opts.count));
        }
        return value;
      },
      i18n: { language: 'ar', changeLanguage: vi.fn() },
    }),
  };
});

const projectsApiMock = { getActivityLog: vi.fn() };

vi.mock('@entities/project', () => ({ projectsApi: projectsApiMock }));

const pageOneLogs = {
  data: [
    {
      id: 1,
      action: 'created',
      action_label: 'إنشاء',
      loggable_type: 'project',
      loggable_type_label: 'مشروع',
      task_title: null,
      user: { id: 1, name: 'مدير النظام' },
      changes: [{ field: 'status', field_label: 'الحالة', old_value: 'draft', new_value: 'in_progress', display_old: 'مسودة', display_new: 'قيد التنفيذ' }],
      old_values: null,
      new_values: { code: 'PRJ-1' },
      ip_address: '127.0.0.1',
      created_at: '2026-06-01T10:00:00Z',
      created_at_formatted: '2026-06-01',
      created_at_human: 'منذ ساعة',
    },
    {
      id: 2,
      action: 'member_added',
      action_label: 'إضافة عضو',
      loggable_type: 'task',
      loggable_type_label: 'مهمة',
      task_title: 'إعداد الخطة',
      user: null,
      changes: [],
      old_values: null,
      new_values: { member_name: 'سارة أحمد', role: 'عضو' },
      ip_address: null,
      created_at: '2026-06-02T10:00:00Z',
      created_at_formatted: '2026-06-02',
      created_at_human: 'اليوم',
    },
    {
      id: 3,
      action: 'risk_added',
      action_label: 'إضافة خطر',
      loggable_type: 'project',
      loggable_type_label: 'مشروع',
      task_title: null,
      user: { id: 2, name: 'محلل المخاطر' },
      changes: [],
      old_values: null,
      new_values: { risk: 'تأخر التوريد', probability: 'high', impact: 'medium' },
      ip_address: null,
      created_at: '2026-06-03T10:00:00Z',
      created_at_formatted: '2026-06-03',
      created_at_human: 'أمس',
    },
  ],
  current_page: 1,
  last_page: 2,
  per_page: 15,
  total: 4,
};

const pageTwoLogs = {
  data: [
    {
      id: 4,
      action: 'attachment_added',
      action_label: 'رفع مرفق',
      loggable_type: 'project',
      loggable_type_label: 'مشروع',
      task_title: null,
      user: { id: 3, name: 'الأرشيف' },
      changes: [],
      old_values: null,
      new_values: { file_name: 'charter.pdf', file_size: 4096 },
      ip_address: null,
      created_at: '2026-06-04T10:00:00Z',
      created_at_formatted: '2026-06-04',
      created_at_human: 'الآن',
    },
  ],
  current_page: 2,
  last_page: 2,
  per_page: 15,
  total: 4,
};

const project = {
  id: 1,
  name: 'منصة التحول المؤسسي',
  code: 'PRJ-1',
  description: 'بطاقة تنفيذية مختصرة',
  objectives: ['رفع النضج التشغيلي', 'تحسين رضا المستفيدين'],
  status: 'in_progress',
  priority: 'critical',
  progress: 72,
  start_date: '2026-01-01',
  end_date: '2026-06-20',
  budget: 100000,
  actual_cost: 125000,
  department: { id: 1, name: 'الإدارة الاستراتيجية' },
  manager: { id: 2, name: 'أحمد القائد' },
  supervisor: { id: 3, name: 'نورة المشرفة' },
  sponsor: { id: 4, name: 'الراعي' },
  creator: { id: 5, name: 'المنشئ' },
  in_scope: ['النطاق الداخلي'],
  out_of_scope: ['النطاق الخارجي'],
  milestones: [
    { id: 1, name: 'مرحلة التحليل', description: null, start_date: '2026-01-01', due_date: '2026-02-01', completed_date: '2026-02-01', status: 'completed', progress: 100 },
    { id: 2, name: 'مرحلة التنفيذ', description: null, start_date: '2026-02-02', due_date: '2026-06-20', completed_date: null, status: 'in_progress', progress: 60 },
  ],
  kpis: [
    { id: 1, indicator: 'نسبة الإنجاز', baseline: '0', target: '100', current_value: '72' },
    { id: 2, indicator: 'رضا المستفيد', baseline: '60', target: '90', current_value: '95' },
  ],
  risks: [
    { id: 1, risk: 'تأخر التوريد', probability: 'high', impact: 'high', response: 'خطة بديلة', status: 'open' },
    { id: 2, risk: 'نقص الموارد', probability: 'medium', impact: 'medium', response: 'تخصيص', status: 'closed' },
  ],
  tasks: [
    { id: 1, title: 'منجز مكتمل', status: 'completed', priority: 'high', start_date: '2026-01-01', due_date: '2026-01-10', assignee: { id: 1, name: 'سارة' } },
    { id: 2, title: 'مهمة جارية', status: 'in_progress', priority: 'critical', start_date: '2026-01-11', due_date: '2020-01-01', assignee: null },
  ],
  stakeholders: [{ id: 1, name: 'جهة مستفيدة', role: 'مالك', organization: 'وزارة', influence: 'high' }],
  members: Array.from({ length: 10 }, (_, index) => ({ id: index + 1, name: `عضو ${index + 1}`, pivot: { role: 'عضو' } })),
};

describe('project widgets wave 2 coverage', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    Element.prototype.scrollIntoView = vi.fn();
    projectsApiMock.getActivityLog.mockResolvedValue(pageOneLogs);
  });

  it('loads activity logs, expands changes, filters, refreshes, loads more, and handles empty/error states', async () => {
    const user = userEvent.setup();
    const { default: ProjectActivityLog } = await import('@widgets/project/ui/ProjectActivityLog');
    const { rerender } = render(<ProjectActivityLog projectId={1} />);

    expect(await screen.findByText('إنشاء')).toBeInTheDocument();
    expect(screen.getByText('إعداد الخطة')).toBeInTheDocument();
    expect(screen.getByText('تم تغيير: الحالة')).toBeInTheDocument();
    await user.click(screen.getByText('تم تغيير: الحالة'));
    expect(await screen.findByText('مسودة')).toBeInTheDocument();
    expect(screen.getByText('قيد التنفيذ')).toBeInTheDocument();
    expect(screen.getByText(/تم إنشاء المشروع برمز/)).toBeInTheDocument();
    expect(screen.getByText('سارة أحمد')).toBeInTheDocument();
    expect(screen.getByText('تأخر التوريد')).toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: 'كل الأنشطة' }));
    await user.click(screen.getByRole('option', { name: 'إضافة خطر' }));
    await waitFor(() => expect(projectsApiMock.getActivityLog).toHaveBeenLastCalledWith(1, { page: '1', per_page: '15', action: 'risk_added' }));

    await user.click(screen.getByRole('button', { name: 'تحديث' }));
    await waitFor(() => expect(projectsApiMock.getActivityLog).toHaveBeenCalled());

    projectsApiMock.getActivityLog.mockResolvedValueOnce(pageTwoLogs);
    await user.click(screen.getByRole('button', { name: /تحميل المزيد/ }));
    expect(await screen.findByText('charter.pdf')).toBeInTheDocument();
    expect(screen.getByText('(4 KB)')).toBeInTheDocument();

    projectsApiMock.getActivityLog.mockResolvedValueOnce({ data: [], current_page: 1, last_page: 1, per_page: 15, total: 0 });
    rerender(<ProjectActivityLog projectId={2} />);
    expect(await screen.findByText('لا توجد سجلات')).toBeInTheDocument();

    projectsApiMock.getActivityLog.mockRejectedValueOnce(new Error('network'));
    rerender(<ProjectActivityLog projectId={3} />);
    await waitFor(() => expect(projectsApiMock.getActivityLog).toHaveBeenCalledWith(3, { page: '1', per_page: '15', action: 'risk_added' }));
  });

  it('renders project report metrics, status fallbacks, empty sections, and print export', async () => {
    const { default: ProjectReportCard } = await import('@widgets/project/ui/ProjectReportCard');

    render(<ProjectReportCard project={project} />);
    expect(screen.getByText('منصة التحول المؤسسي')).toBeInTheDocument();
    expect(screen.getByText('حرجة')).toBeInTheDocument();
    expect(screen.getByText('الإدارة الاستراتيجية')).toBeInTheDocument();
    expect(screen.getByText('أحمد القائد')).toBeInTheDocument();
    expect(screen.getAllByText('72%').length).toBeGreaterThan(0);
    expect(screen.getByText('مرحلة التحليل')).toBeInTheDocument();
    expect(screen.getByText('نسبة الإنجاز')).toBeInTheDocument();
    expect(screen.getByText('تأخر التوريد')).toBeInTheDocument();
    expect(screen.getByText('+2')).toBeInTheDocument();

    // The print path now clones the card into a hidden same-origin iframe and
    // writes the document title via textContent (no document.write of user data).
    await userEvent.click(screen.getByRole('button', { name: /طباعة/ }));
    const iframe = document.querySelector('iframe');
    expect(iframe).not.toBeNull();
    expect(iframe!.contentDocument?.title).toContain('PRJ-1 - منصة التحول المؤسسي');

    render(<ProjectReportCard project={{ ...project, status: 'custom', priority: 'unknown', progress: 15, end_date: null, budget: null, actual_cost: null, objectives: [], milestones: [], kpis: [], risks: [], tasks: [], members: [] }} />);
    expect(screen.getByText('custom')).toBeInTheDocument();
    expect(screen.getByText('unknown')).toBeInTheDocument();
    expect(screen.getByText('لا توجد مراحل')).toBeInTheDocument();
    expect(screen.getByText('لا توجد مؤشرات')).toBeInTheDocument();
    expect(screen.getByText('لا توجد مخاطر')).toBeInTheDocument();
  });
});
