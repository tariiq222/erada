import * as React from 'react';
import { createPortal } from 'react-dom';
import {IconSearch, IconCornerDownLeft, IconArrowUp, IconArrowDown} from '@tabler/icons-react';
import { cn } from '@shared/lib/utils';
import { useFocusTrap } from '@shared/lib/hooks/useFocusTrap';
import { Kbd } from './Kbd';
import type { NavGroup, NavItem } from '@shared/nasaq/app';

const RECENT_KEY = 'erada:command-palette:recent';
const RECENT_MAX = 5;
const COMMAND_PALETTE_LISTBOX_ID = 'command-palette-results';

export interface CommandPaletteProps {
  open: boolean;
  onClose: () => void;
  groups: NavGroup[];
  labels?: Record<string, string>;
  onNavigate: (path: string) => void;
  t: (key: string, fallback?: string) => string;
}

type FlatItem = NavItem & { __groupId: string; __groupLabel?: string };

function flatten(items: NavItem[], groupId: string, groupLabel?: string, out: FlatItem[] = []): FlatItem[] {
  for (const item of items) {
    out.push({ ...item, __groupId: groupId, __groupLabel: groupLabel });
    if (item.children && item.children.length > 0) {
      flatten(item.children, groupId, groupLabel, out);
    }
  }
  return out;
}

function readRecent(): string[] {
  if (typeof window === 'undefined') return [];
  try {
    const raw = window.localStorage.getItem(RECENT_KEY);
    if (!raw) return [];
    const parsed = JSON.parse(raw);
    return Array.isArray(parsed) ? parsed.filter((p): p is string => typeof p === 'string') : [];
  } catch {
    return [];
  }
}

function writeRecent(paths: string[]) {
  if (typeof window === 'undefined') return;
  try {
    window.localStorage.setItem(RECENT_KEY, JSON.stringify(paths.slice(0, RECENT_MAX)));
  } catch {
    /* ignore quota / disabled storage */
  }
}

function iconFor(name: string): string {
  const map: Record<string, string> = {
    grid: '▦', check: '✓', list: '☰', folder: '▤', target: '◎', alert: '⚠', inbox: '✉',
    shield: '⛨', users: '♟', building: '🏢', badge: '◈', settings: '⚙', plus: '+',
    gauge: '◐', flag: '⚑', trend: '↗', log: '≡',
  };
  return map[name] ?? '•';
}

function optionIdFor(item: FlatItem): string {
  const stableId = `${item.__groupId}-${item.path}`.replace(/[^A-Za-z0-9_-]+/g, '-').replace(/^-+|-+$/g, '') || 'item';
  return `${COMMAND_PALETTE_LISTBOX_ID}-option-${stableId}`;
}

const CommandPalette: React.FC<CommandPaletteProps> = ({
  open,
  onClose,
  groups,
  labels = {},
  onNavigate,
  t,
}) => {
  const [query, setQuery] = React.useState('');
  const [highlight, setHighlight] = React.useState(0);
  const [recentPaths, setRecentPaths] = React.useState<string[]>([]);
  const inputRef = React.useRef<HTMLInputElement>(null);
  const listRef = React.useRef<HTMLDivElement>(null);
  const dialogRef = useFocusTrap<HTMLDivElement>(open);

  // Build flat list of every accessible item (parents + leaves).
  const allItems = React.useMemo<FlatItem[]>(
    () => groups.flatMap((g) => flatten(g.items, g.id, g.label)),
    [groups],
  );

  // Map path → resolved item so we can render "recent" paths.
  const byPath = React.useMemo(() => {
    const m = new Map<string, FlatItem>();
    for (const it of allItems) m.set(it.path, it);
    return m;
  }, [allItems]);

  const recentItems = React.useMemo<FlatItem[]>(() => {
    const out: FlatItem[] = [];
    const seen = new Set<string>();
    for (const p of recentPaths) {
      const it = byPath.get(p);
      if (it && !seen.has(p)) {
        out.push(it);
        seen.add(p);
      }
    }
    return out;
  }, [recentPaths, byPath]);

  // Filtered results based on query.
  const filtered = React.useMemo<FlatItem[]>(() => {
    const q = query.trim().toLowerCase();
    if (!q) return [];
    return allItems.filter((it) => it.label.toLowerCase().includes(q));
  }, [query, allItems]);

  // When no query, show recents. Otherwise show filtered results.
  const visibleItems = query.trim() ? filtered : recentItems;
  const showRecents = !query.trim() && recentItems.length > 0;
  const showEmpty = query.trim().length > 0 && filtered.length === 0;
  const showNoRecents = !query.trim() && recentItems.length === 0;

  // Reset state when opened, load recents from storage.
  React.useEffect(() => {
    if (!open) return;
    setQuery('');
    setHighlight(0);
    setRecentPaths(readRecent());
    // Focus on next tick so the input is mounted.
    const id = window.setTimeout(() => inputRef.current?.focus(), 0);
    return () => window.clearTimeout(id);
  }, [open]);

  // Body scroll lock + escape handler (mirrors Modal.tsx).
  React.useEffect(() => {
    if (!open) return;
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') {
        e.preventDefault();
        onClose();
      }
    };
    document.addEventListener('keydown', onKey);
    const prevOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    return () => {
      document.removeEventListener('keydown', onKey);
      document.body.style.overflow = prevOverflow;
    };
  }, [open, onClose]);

  // Clamp highlight to current visible range.
  React.useEffect(() => {
    if (highlight >= visibleItems.length) {
      setHighlight(Math.max(0, visibleItems.length - 1));
    }
  }, [visibleItems.length, highlight]);

  // Scroll highlighted item into view.
  React.useEffect(() => {
    if (!open) return;
    const list = listRef.current;
    if (!list) return;
    const el = list.querySelector<HTMLElement>(`[data-cp-index="${highlight}"]`);
    el?.scrollIntoView({ block: 'nearest' });
  }, [highlight, open, visibleItems.length]);

  if (!open) return null;

  const handleKeyDown = (e: React.KeyboardEvent<HTMLInputElement>) => {
    if (e.key === 'ArrowDown') {
      e.preventDefault();
      if (visibleItems.length > 0) {
        setHighlight((h) => (h + 1) % visibleItems.length);
      }
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      if (visibleItems.length > 0) {
        setHighlight((h) => (h - 1 + visibleItems.length) % visibleItems.length);
      }
    } else if (e.key === 'Enter') {
      e.preventDefault();
      const item = visibleItems[highlight];
      if (item) activate(item);
    }
  };

  const activate = (item: FlatItem) => {
    const next = [item.path, ...recentPaths.filter((p) => p !== item.path)].slice(0, RECENT_MAX);
    writeRecent(next);
    onClose();
    onNavigate(item.path);
  };

  const handleOverlayClick = (e: React.MouseEvent<HTMLDivElement>) => {
    if (e.target === e.currentTarget) onClose();
  };

  const headerTitle = t('common.command_palette', 'لوحة الأوامر');
  const searchPlaceholder = t('common.search_system', 'ابحث في النظام...');
  const noResults = t('common.no_results', 'لا توجد نتائج');
  const noRecents = t('common.no_recent', 'لا توجد عناصر حديثة');
  const recentLabel = t('common.recent', 'حديثاً');
  const hint = t('common.palette_hint', '↑↓ للتنقل   ↵ للفتح   esc للإغلاق');
  const activeDescendant = visibleItems[highlight] ? optionIdFor(visibleItems[highlight]) : undefined;
  const sectionLabels: Record<string, string> = {
    main: labels['main'] || t('nav.main', 'الرئيسية'),
    ops: labels['ops'] || t('nav.operations', 'العمليات'),
    admin: labels['admin'] || t('nav.administration', 'الإدارة'),
  };

  return createPortal(
    <div
      onClick={handleOverlayClick}
      role="presentation"
      className={cn(
        'fixed inset-0 z-50 flex items-start justify-center p-2 sm:p-4',
        'pt-[15vh]',
        'bg-[var(--surface-overlay)]',
        'animate-in fade-in duration-150 motion-reduce:animate-none',
      )}
    >
      <div
        ref={dialogRef}
        role="dialog"
        aria-modal="true"
        aria-label={headerTitle}
        className={cn(
          'relative w-full max-w-[560px]',
          'rounded-[var(--r-lg)] border border-[var(--border)]',
          'bg-[var(--surface)] text-[var(--text)]',
          'shadow-[var(--shadow-lg)]',
          'overflow-hidden flex flex-col',
          'animate-in zoom-in-95 duration-200 motion-reduce:animate-none',
        )}
      >
        <div
          className={cn(
            'flex items-center gap-2 px-4 py-3',
            'border-b border-[var(--border)]',
          )}
        >
          <IconSearch className="h-4 w-4 text-[var(--text-3)] shrink-0" />
          <input
            ref={inputRef}
            type="text"
            value={query}
            onChange={(e) => {
              setQuery(e.target.value);
              setHighlight(0);
            }}
            onKeyDown={handleKeyDown}
            placeholder={searchPlaceholder}
            aria-label={searchPlaceholder}
            role="combobox"
            aria-expanded="true"
            aria-controls={COMMAND_PALETTE_LISTBOX_ID}
            aria-activedescendant={activeDescendant}
            aria-autocomplete="list"
            className={cn(
              'flex-1 bg-transparent outline-none border-0',
              'text-[15px] text-[var(--text)]',
              'placeholder:text-[var(--text-3)]',
              'dir-auto',
            )}
            autoComplete="off"
            spellCheck={false}
          />
          <Kbd>esc</Kbd>
        </div>

        <div
          id={COMMAND_PALETTE_LISTBOX_ID}
          ref={listRef}
          role="listbox"
          aria-label={t('common.palette_results', 'نتائج لوحة الأوامر')}
          className={cn(
            'max-h-[50vh] overflow-y-auto py-2',
          )}
        >
          {showRecents && (
            <div role="presentation" className="px-3 pt-1 pb-2 text-[11px] uppercase tracking-wider text-[var(--text-3)]">
              {recentLabel}
            </div>
          )}
          {showRecents && recentItems.map((item, idx) => (
            <button
              key={`recent-${item.path}`}
              id={optionIdFor(item)}
              data-cp-index={idx}
              role="option"
              aria-selected={highlight === idx}
              type="button"
              onMouseEnter={() => setHighlight(idx)}
              onClick={() => activate(item)}
              className={cn(
                'min-h-11 w-full flex items-center gap-3 px-3 py-2 text-start',
                'text-sm text-[var(--text)]',
                highlight === idx
                  ? 'bg-[var(--surface-2)]'
                  : 'hover:bg-[var(--surface-2)]',
              )}
            >
              <span className="text-[var(--text-3)] w-5 text-center shrink-0" aria-hidden>
                {iconFor(item.icon)}
              </span>
              <span className="flex-1 truncate">{item.label}</span>
              {item.__groupLabel && (
                <span className="text-[11px] text-[var(--text-3)] shrink-0">
                  {item.__groupLabel}
                </span>
              )}
            </button>
          ))}
          {query.trim() && filtered.length > 0 && (
            <div role="presentation" className="px-3 pt-1 pb-2 text-[11px] uppercase tracking-wider text-[var(--text-3)]">
              {sectionLabels[filtered[0].__groupId] || ''}
            </div>
          )}
          {query.trim() && filtered.map((item, idx) => (
            <button
              key={`hit-${item.path}-${idx}`}
              id={optionIdFor(item)}
              data-cp-index={idx}
              role="option"
              aria-selected={highlight === idx}
              type="button"
              onMouseEnter={() => setHighlight(idx)}
              onClick={() => activate(item)}
              className={cn(
                'min-h-11 w-full flex items-center gap-3 px-3 py-2 text-start',
                'text-sm text-[var(--text)]',
                highlight === idx
                  ? 'bg-[var(--surface-2)]'
                  : 'hover:bg-[var(--surface-2)]',
              )}
            >
              <span className="text-[var(--text-3)] w-5 text-center shrink-0" aria-hidden>
                {iconFor(item.icon)}
              </span>
              <span className="flex-1 truncate">{item.label}</span>
              <span className="text-[11px] text-[var(--text-3)] shrink-0">
                {sectionLabels[item.__groupId] || ''}
              </span>
            </button>
          ))}
          {showEmpty && (
            <div className="px-6 py-10 text-center text-sm text-[var(--text-3)]">
              {noResults}
            </div>
          )}
          {showNoRecents && (
            <div className="px-6 py-10 text-center text-sm text-[var(--text-3)]">
              {noRecents}
            </div>
          )}
        </div>

        <div
          className={cn(
            'flex items-center justify-center gap-4 px-4 py-2',
            'text-[11px] text-[var(--text-3)]',
            'border-t border-[var(--border)] bg-[var(--surface-2)]',
          )}
        >
          <span className="inline-flex items-center gap-1">
            <Kbd><IconArrowUp className="h-3 w-3" /></Kbd>
            <Kbd><IconArrowDown className="h-3 w-3" /></Kbd>
            {t('common.palette_nav', 'للتنقل')}
          </span>
          <span className="inline-flex items-center gap-1">
            <Kbd><IconCornerDownLeft className="h-3 w-3" /></Kbd>
            {t('common.palette_open', 'للفتح')}
          </span>
          <span className="inline-flex items-center gap-1">
            <Kbd>esc</Kbd>
            {t('common.palette_close', 'للإغلاق')}
          </span>
        </div>
        <span className="sr-only">{hint}</span>
      </div>
    </div>,
    document.body,
  );
};

CommandPalette.displayName = 'CommandPalette';

export { CommandPalette };
