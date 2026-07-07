import { describe, it, expect, vi, beforeAll, beforeEach } from 'vitest';
import { render, screen, cleanup } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import React from 'react';
import { CommandPalette } from '@shared/ui/CommandPalette';
import type { NavGroup } from '@shared/nasaq/app';

beforeAll(() => {
  // jsdom does not implement scrollIntoView; the palette calls it when the
  // highlight changes, so stub it on the prototype to keep the test hermetic.
  if (!HTMLElement.prototype.scrollIntoView) {
    HTMLElement.prototype.scrollIntoView = vi.fn();
  }
});

const tMock = (_key: string, fallback?: string) => fallback ?? _key;

const groups: NavGroup[] = [
  {
    id: 'main',
    items: [
      { key: 'dash', label: 'Dashboard', icon: 'grid', path: '/dashboard' },
      { key: 'tasks', label: 'Tasks', icon: 'check', path: '/tasks' },
      { key: 'my', label: 'My Tasks', icon: 'list', path: '/my-tasks' },
    ],
  },
];

const renderOpen = (props: Partial<React.ComponentProps<typeof CommandPalette>> = {}) => {
  const onClose = vi.fn();
  const onNavigate = vi.fn();
  render(
    <CommandPalette
      open
      onClose={onClose}
      groups={groups}
      onNavigate={onNavigate}
      t={tMock}
      {...props}
    />,
  );
  return { onClose, onNavigate };
};

// Active item: contains `bg-[var(--surface-2)]` without the `hover:` variant prefix.
const highlightedIndex = (): number | null => {
  const all = Array.from(document.body.querySelectorAll<HTMLElement>('[data-cp-index]'));
  for (const el of all) {
    const cls = el.className;
    if (cls.includes('bg-[var(--surface-2)]') && !cls.includes('hover:bg-[var(--surface-2)]')) {
      return Number(el.getAttribute('data-cp-index'));
    }
  }
  return null;
};

const allDataCpIndices = (): number[] =>
  Array.from(document.body.querySelectorAll<HTMLElement>('[data-cp-index]'))
    .map((el) => Number(el.getAttribute('data-cp-index')));

describe('CommandPalette', () => {
  beforeEach(() => {
    cleanup();
    localStorage.clear();
  });

  it('renders the search input with a placeholder when open', () => {
    renderOpen();
    const input = screen.getByPlaceholderText('ابحث في النظام...') as HTMLInputElement;
    expect(input).toBeInTheDocument();
    expect(input.tagName.toLowerCase()).toBe('input');
  });

  it('does not render anything when closed', () => {
    render(
      <CommandPalette
        open={false}
        onClose={vi.fn()}
        groups={groups}
        onNavigate={vi.fn()}
        t={tMock}
      />,
    );
    expect(screen.queryByPlaceholderText('ابحث في النظام...')).toBeNull();
  });

  it('filters the list as the user types and shows no results when nothing matches', async () => {
    const user = userEvent.setup();
    renderOpen();
    const input = screen.getByPlaceholderText('ابحث في النظام...') as HTMLInputElement;

    // Initially (no query, no recents) — none of the items should be present.
    expect(allDataCpIndices()).toEqual([]);

    // Typing a substring of one item should reveal exactly that one.
    await user.type(input, 'Dash');
    expect(allDataCpIndices()).toEqual([0]);
    expect(document.body.textContent).toContain('Dashboard');
    expect(document.body.textContent).not.toContain('Tasks');

    // Typing something that matches nothing should show the no-results message.
    await user.clear(input);
    await user.type(input, 'zzz-no-match');
    expect(screen.getByText('لا توجد نتائج')).toBeInTheDocument();
  });

  it('ArrowDown moves the highlight to the next item', async () => {
    const user = userEvent.setup();
    renderOpen();
    const input = screen.getByPlaceholderText('ابحث في النظام...') as HTMLInputElement;
    // 'a' matches Dashboard, Tasks, My Tasks — in source order.
    await user.type(input, 'a');

    expect(highlightedIndex()).toBe(0);

    await user.keyboard('{ArrowDown}');
    expect(highlightedIndex()).toBe(1);

    await user.keyboard('{ArrowDown}');
    expect(highlightedIndex()).toBe(2);
  });

  it('Enter calls onNavigate with the currently highlighted path', async () => {
    const user = userEvent.setup();
    const { onNavigate, onClose } = renderOpen();
    const input = screen.getByPlaceholderText('ابحث في النظام...') as HTMLInputElement;
    await user.type(input, 'Dash');
    expect(highlightedIndex()).toBe(0);

    await user.keyboard('{Enter}');

    expect(onNavigate).toHaveBeenCalledTimes(1);
    expect(onNavigate).toHaveBeenCalledWith('/dashboard');
    expect(onClose).toHaveBeenCalledTimes(1);
  });

  it('Escape calls onClose', async () => {
    const user = userEvent.setup();
    const { onClose } = renderOpen();
    const input = screen.getByPlaceholderText('ابحث في النظام...') as HTMLInputElement;
    input.focus();
    await user.keyboard('{Escape}');
    expect(onClose).toHaveBeenCalledTimes(1);
  });
});
