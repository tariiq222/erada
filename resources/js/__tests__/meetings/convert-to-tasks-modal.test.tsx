import React from 'react';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, options?: { defaultValue?: string }) => options?.defaultValue ?? key,
    i18n: { changeLanguage: vi.fn(), language: 'ar' },
  }),
}));

const mocks = vi.hoisted(() => ({
  convertToTasks: vi.fn(),
}));

vi.mock('@shared/api/client', () => ({
  api: { post: vi.fn(), get: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}));

vi.mock('@features/meetings/resolutions/api', () => ({
  resolutionsApi: {
    convertToTasks: mocks.convertToTasks,
    listForMeeting: vi.fn(),
    createForMeeting: vi.fn(),
    update: vi.fn(),
    remove: vi.fn(),
    start: vi.fn(),
    hold: vi.fn(),
    releaseHold: vi.fn(),
    complete: vi.fn(),
    cancel: vi.fn(),
  },
}));

vi.mock('@shared/ui/Toast', () => ({
  useToast: () => ({ success: vi.fn(), error: vi.fn(), warning: vi.fn(), info: vi.fn(), showToast: vi.fn() }),
  ToastProvider: ({ children }: { children: React.ReactNode }) => children,
}));

import ConvertToTasksModal from '@features/meetings/resolutions/ConvertToTasksModal';
import type { MeetingResolution } from '@features/meetings/resolutions/types';

const baseResolution: MeetingResolution = {
  id: 1,
  reference_number: 'RES-2026-0001',
  organization_id: 1,
  meeting_id: 1,
  kind: 'decision',
  title: 'مخرج للاختبار',
  description: null,
  owner_id: 1,
  status: 'open',
  priority: 'medium',
  due_date: null,
  hold_reason: null,
  hold_until: null,
  hold_by: null,
  hold_at: null,
  created_by: 1,
  completed_at: null,
  cancelled_at: null,
  created_at: '2026-07-01T00:00:00.000Z',
  updated_at: '2026-07-01T00:00:00.000Z',
  status_label: 'مفتوح',
  owner: { id: 1, name: 'مسؤول' },
  links: [],
};

describe('ConvertToTasksModal', () => {
  beforeEach(() => {
    mocks.convertToTasks.mockReset();
  });

  it('does not render approve / reject / adopt / deliberate buttons', () => {
    render(<ConvertToTasksModal open={true} resolution={baseResolution} onClose={() => {}} onConverted={() => {}} />);
    const html = document.body.innerHTML;
    expect(html).not.toMatch(/approve|اعتماد/i);
    expect(html).not.toMatch(/reject|رفض/i);
    expect(html).not.toMatch(/adopt/i);
    expect(html).not.toMatch(/deliberate/i);
  });

  it('starts with exactly one empty task row', () => {
    render(<ConvertToTasksModal open={true} resolution={baseResolution} onClose={() => {}} onConverted={() => {}} />);
    expect(screen.getByTestId('convert-task-row-0')).toBeTruthy();
    // Single row: no remove button on the first row
    expect(screen.queryByTestId('convert-task-remove-0')).toBeNull();
  });

  it('adds rows when the user clicks the add button', () => {
    render(<ConvertToTasksModal open={true} resolution={baseResolution} onClose={() => {}} onConverted={() => {}} />);
    fireEvent.click(screen.getByTestId('convert-task-add'));
    fireEvent.click(screen.getByTestId('convert-task-add'));
    expect(screen.getByTestId('convert-task-row-1')).toBeTruthy();
    expect(screen.getByTestId('convert-task-row-2')).toBeTruthy();
  });

  it('removes a row when the user clicks the per-row delete', () => {
    render(<ConvertToTasksModal open={true} resolution={baseResolution} onClose={() => {}} onConverted={() => {}} />);
    fireEvent.click(screen.getByTestId('convert-task-add')); // now 2 rows
    fireEvent.click(screen.getByTestId('convert-task-remove-1')); // remove row 1
    expect(screen.queryByTestId('convert-task-row-1')).toBeNull();
    expect(screen.getByTestId('convert-task-row-0')).toBeTruthy();
  });

  it('rejects submission with empty title (shows error)', async () => {
    mocks.convertToTasks.mockResolvedValue({ message: 'ok', resolution: baseResolution, tasks: [] });
    render(<ConvertToTasksModal open={true} resolution={baseResolution} onClose={() => {}} onConverted={() => {}} />);

    const titleInput = screen.getByTestId('convert-task-title-0');
    fireEvent.change(titleInput, { target: { value: '   ' } }); // whitespace only
    const assigneeInput = screen.getByTestId('convert-task-assignee-0');
    fireEvent.change(assigneeInput, { target: { value: '1' } });

    fireEvent.click(screen.getByTestId('convert-task-submit'));

    await waitFor(() => {
      expect(mocks.convertToTasks).not.toHaveBeenCalled();
    });
  });

  it('rejects submission with no assignee_id', async () => {
    mocks.convertToTasks.mockResolvedValue({ message: 'ok', resolution: baseResolution, tasks: [] });
    render(<ConvertToTasksModal open={true} resolution={baseResolution} onClose={() => {}} onConverted={() => {}} />);

    fireEvent.change(screen.getByTestId('convert-task-title-0'), { target: { value: 'مهمة' } });
    // assignee_id left at 0 (blank)

    fireEvent.click(screen.getByTestId('convert-task-submit'));

    await waitFor(() => {
      expect(mocks.convertToTasks).not.toHaveBeenCalled();
    });
  });

  it('submits valid payload with title and assignee_id', async () => {
    mocks.convertToTasks.mockResolvedValue({
      message: 'تم',
      resolution: { ...baseResolution, status: 'converted_to_tasks' },
      tasks: [{ id: 1, title: 'مهمة ١', status: 'todo', due_date: null, assignee_id: 1 }],
    });
    const onClose = vi.fn();
    const onConverted = vi.fn();

    render(<ConvertToTasksModal open={true} resolution={baseResolution} onClose={onClose} onConverted={onConverted} />);

    fireEvent.change(screen.getByTestId('convert-task-title-0'), { target: { value: 'مهمة ١' } });
    fireEvent.change(screen.getByTestId('convert-task-assignee-0'), { target: { value: '1' } });

    fireEvent.click(screen.getByTestId('convert-task-submit'));

    await waitFor(() => {
      expect(mocks.convertToTasks).toHaveBeenCalledWith(1, {
        tasks: [{
          title: 'مهمة ١',
          description: null,
          assignee_id: 1,
          due_date: null,
          priority: 'medium',
          project_id: null,
        }],
      });
    });
    await waitFor(() => {
      expect(onConverted).toHaveBeenCalled();
      expect(onClose).toHaveBeenCalled();
    });
  });

  it('cancels and does not call API when cancel button clicked', () => {
    const onClose = vi.fn();
    render(<ConvertToTasksModal open={true} resolution={baseResolution} onClose={onClose} onConverted={() => {}} />);
    fireEvent.click(screen.getByText(/إلغاء/));
    expect(onClose).toHaveBeenCalled();
    expect(mocks.convertToTasks).not.toHaveBeenCalled();
  });

  it('displays server error message on API failure', async () => {
    mocks.convertToTasks.mockRejectedValue(new Error('فشل التحويل'));
    render(<ConvertToTasksModal open={true} resolution={baseResolution} onClose={() => {}} onConverted={() => {}} />);

    fireEvent.change(screen.getByTestId('convert-task-title-0'), { target: { value: 'مهمة' } });
    fireEvent.change(screen.getByTestId('convert-task-assignee-0'), { target: { value: '1' } });
    fireEvent.click(screen.getByTestId('convert-task-submit'));

    await waitFor(() => {
      expect(screen.getByText(/فشل التحويل/)).toBeTruthy();
    });
  });
});