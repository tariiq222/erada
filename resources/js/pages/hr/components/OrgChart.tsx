import React, { useState, useEffect, useRef, useCallback, useMemo, useLayoutEffect } from 'react';
import { useTranslation } from 'react-i18next';
import {IconMaximize, IconMinimize, IconNetwork, IconAlertCircle, IconPlus, IconMinus, IconFocusCentered, IconArrowsMaximize, IconArrowsMinimize} from '@tabler/icons-react';
import { Button, IconButton, Skeleton } from '@shared/ui';
import { departmentsApi } from '@entities/hr';
import { OrgChartTree } from './OrgChartTree';
import type { TreeDepartment } from './departmentTypes';

interface OrgChartProps {
  // Triggered by the per-card edit icon (e.g. navigate to the edit page).
  onDepartmentClick?: (dept: TreeDepartment) => void;
  canEdit?: boolean;
}

const getAllNodeIds = (nodes: TreeDepartment[]): number[] => {
  const ids: number[] = [];
  const traverse = (node: TreeDepartment) => {
    ids.push(node.id);
    node.children?.forEach(traverse);
  };
  nodes.forEach(traverse);
  return ids;
};

// Build a child-id -> parent-id map for the whole forest.
const buildParentMap = (nodes: TreeDepartment[]): Map<number, number | null> => {
  const map = new Map<number, number | null>();
  const walk = (node: TreeDepartment, parent: number | null) => {
    map.set(node.id, parent);
    node.children?.forEach(c => walk(c, node.id));
  };
  nodes.forEach(n => walk(n, null));
  return map;
};

export const OrgChart: React.FC<OrgChartProps> = ({ onDepartmentClick, canEdit }) => {
  const { t } = useTranslation();
  const [treeData, setTreeData] = useState<TreeDepartment[]>([]);
  const [expandedNodes, setExpandedNodes] = useState<Set<number>>(new Set());
  const [selectedId, setSelectedId] = useState<number | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  // Ancestor chain (root..selected) used to highlight the path.
  const pathOrder = useMemo<number[]>(() => {
    if (selectedId == null) return [];
    const parents = buildParentMap(treeData);
    const chain: number[] = [];
    let cur: number | null = selectedId;
    while (cur != null) {
      chain.unshift(cur);
      cur = parents.get(cur) ?? null;
    }
    return chain;
  }, [selectedId, treeData]);
  const pathIds = useMemo(() => new Set(pathOrder), [pathOrder]);

  useEffect(() => {
    fetchTreeData();
  }, []);

  const fetchTreeData = async () => {
    setIsLoading(true);
    setError(null);
    try {
      const response = await departmentsApi.getTree();
      setTreeData(response as TreeDepartment[]);
      // Auto-expand root nodes
      const rootIds = (response as TreeDepartment[]).map(d => d.id);
      setExpandedNodes(new Set(rootIds));
    } catch (err) {
      setError(t('hr.org_chart_load_error'));
      console.error('Failed to fetch tree data:', err);
    } finally {
      setIsLoading(false);
    }
  };

  const toggleNode = (id: number) => {
    setExpandedNodes(prev => {
      const next = new Set(prev);
      if (next.has(id)) {
        next.delete(id);
      } else {
        next.add(id);
      }
      return next;
    });
  };

  const expandAll = () => {
    const allIds = getAllNodeIds(treeData);
    setExpandedNodes(new Set(allIds));
  };

  const collapseAll = () => {
    setExpandedNodes(new Set());
  };

  // --- Board-style pan/zoom (no external library, plain CSS transform) ---
  const viewportRef = useRef<HTMLDivElement>(null);
  const [view, setView] = useState({ scale: 1, tx: 0, ty: 0 });
  const viewRef = useRef(view);
  viewRef.current = view;

  const MIN_SCALE = 0.3;
  const MAX_SCALE = 2.5;
  const clampScale = (s: number) => Math.min(MAX_SCALE, Math.max(MIN_SCALE, s));

  // Zoom toward a point (px, py) measured from the viewport's top-left.
  const zoomAt = useCallback((factor: number, px: number, py: number) => {
    setView(v => {
      const scale = clampScale(v.scale * factor);
      const ratio = scale / v.scale;
      return { scale, tx: px - (px - v.tx) * ratio, ty: py - (py - v.ty) * ratio };
    });
  }, []);

  const zoomButton = (factor: number) => {
    const el = viewportRef.current;
    if (!el) return;
    zoomAt(factor, el.clientWidth / 2, el.clientHeight / 2);
  };

  const resetView = () => setView({ scale: 1, tx: 0, ty: 0 });

  // Fit the whole tree into the viewport and center it.
  const fitView = useCallback(() => {
    const el = viewportRef.current;
    const content = contentRef.current;
    if (!el || !content) return;
    const cw = content.offsetWidth;
    const ch = content.offsetHeight;
    if (!cw || !ch) return;
    const scale = clampScale(Math.min(el.clientWidth / cw, el.clientHeight / ch, 1));
    setView({ scale, tx: (el.clientWidth - cw * scale) / 2, ty: (el.clientHeight - ch * scale) / 2 });
  }, []);

  // Fullscreen the board
  const [isFullscreen, setIsFullscreen] = useState(false);
  const toggleFullscreen = () => {
    if (document.fullscreenElement) {
      document.exitFullscreen();
    } else {
      viewportRef.current?.requestFullscreen?.();
    }
  };
  useEffect(() => {
    const onChange = () => setIsFullscreen(!!document.fullscreenElement);
    document.addEventListener('fullscreenchange', onChange);
    return () => document.removeEventListener('fullscreenchange', onChange);
  }, []);

  // Wheel-to-zoom: attached non-passively so we can prevent page scroll.
  useEffect(() => {
    const el = viewportRef.current;
    if (!el) return;
    const onWheel = (e: globalThis.WheelEvent) => {
      e.preventDefault();
      const rect = el.getBoundingClientRect();
      zoomAt(e.deltaY < 0 ? 1.1 : 1 / 1.1, e.clientX - rect.left, e.clientY - rect.top);
    };
    el.addEventListener('wheel', onWheel, { passive: false });
    return () => el.removeEventListener('wheel', onWheel);
  }, [zoomAt, isLoading]);

  // Drag empty space to pan (clicks on a node card are left alone).
  const onPointerDown = (e: React.PointerEvent) => {
    if ((e.target as HTMLElement).closest('[data-orgnode]')) return;
    e.preventDefault();
    const start = { x: e.clientX, y: e.clientY, tx: viewRef.current.tx, ty: viewRef.current.ty };
    const move = (ev: globalThis.PointerEvent) =>
      setView(v => ({ ...v, tx: start.tx + (ev.clientX - start.x), ty: start.ty + (ev.clientY - start.y) }));
    const up = () => {
      window.removeEventListener('pointermove', move);
      window.removeEventListener('pointerup', up);
    };
    window.addEventListener('pointermove', move);
    window.addEventListener('pointerup', up);
  };

  const selectNode = (id: number) => setSelectedId(prev => (prev === id ? null : id));

  // SVG overlay tracing the path from the root to the selected node. The SVG
  // lives inside the transformed content layer, so coordinates are measured in
  // the content's own (unscaled) space and the path pans/zooms with the tree.
  const contentRef = useRef<HTMLDivElement>(null);
  const [pathD, setPathD] = useState('');
  useLayoutEffect(() => {
    const content = contentRef.current;
    if (!content || pathOrder.length < 2) {
      setPathD('');
      return;
    }
    const cRect = content.getBoundingClientRect();
    const s = viewRef.current.scale || 1;
    const box = (id: number) => {
      const el = content.querySelector<HTMLElement>(`[data-node-id="${id}"]`);
      if (!el) return null;
      const r = el.getBoundingClientRect();
      const x = (r.left - cRect.left) / s;
      const y = (r.top - cRect.top) / s;
      return { cx: x + r.width / s / 2, top: y, bottom: y + r.height / s };
    };
    let d = '';
    for (let i = 0; i < pathOrder.length - 1; i++) {
      const a = box(pathOrder[i]);
      const b = box(pathOrder[i + 1]);
      if (!a || !b) continue;
      const midY = (a.bottom + b.top) / 2;
      d += `M ${a.cx} ${a.bottom} V ${midY} H ${b.cx} V ${b.top} `;
    }
    setPathD(d);
    // Coordinates are unscaled, so the path is independent of zoom/pan.
  }, [pathOrder, expandedNodes, treeData]);

  if (isLoading) {
    return (
      <div className="flex items-center justify-center h-64 bg-[var(--surface-subtle)] rounded-lg border border-[var(--border-default)]">
        <Skeleton className="h-32 w-3/4" variant="rounded" />
      </div>
    );
  }

  if (error) {
    return (
      <div className="flex flex-col items-center justify-center h-64 bg-[var(--status-danger-subtle)] rounded-lg border border-[var(--status-danger-subtle)]">
        <IconAlertCircle className="h-10 w-10 text-[var(--status-danger)] mb-3" />
        <p className="text-[var(--status-danger)] mb-4">{error}</p>
        <Button variant="outline" size="sm" onClick={fetchTreeData}>
          {t('common.retry')}
        </Button>
      </div>
    );
  }

  if (treeData.length === 0) {
    return (
      <div className="flex flex-col items-center justify-center h-64 bg-[var(--surface-subtle)] rounded-lg border border-[var(--border-default)]">
        <IconNetwork className="h-12 w-12 text-[var(--text-tertiary)] mb-4" />
        <h3 className="text-lg font-medium text-[var(--text-primary)] mb-2">{t('hr.no_departments')}</h3>
        <p className="text-[var(--text-secondary)] text-sm">{t('hr.start_adding_departments')}</p>
      </div>
    );
  }

  return (
    <div className="space-y-4">
      {/* Board: drag empty space to pan, wheel/buttons to zoom */}
      <div
        ref={viewportRef}
        onPointerDown={onPointerDown}
        className="org-board relative bg-[var(--surface-subtle)] rounded-lg border border-[var(--border-default)] overflow-hidden h-[600px] cursor-grab active:cursor-grabbing touch-none select-none"
      >
        {/* Persistent header toolbar (stays visible, including fullscreen) */}
        <div className="absolute top-4 right-4 z-10 flex items-center gap-1 rounded-lg border border-[var(--border-default)] bg-[var(--surface-base)] p-1 shadow-sm">
          <Button variant="ghost" size="sm" onClick={expandAll} leftIcon={<IconMaximize className="h-4 w-4" />}>
            {t('hr.expand_all')}
          </Button>
          <Button variant="ghost" size="sm" onClick={collapseAll} leftIcon={<IconMinimize className="h-4 w-4" />}>
            {t('hr.collapse_all')}
          </Button>
          <Button variant="ghost" size="sm" onClick={fitView} leftIcon={<IconFocusCentered className="h-4 w-4" />}>
            {t('hr.center_view')}
          </Button>
        </div>
        <div
          ref={contentRef}
          className="absolute top-0 left-0 origin-top-left p-6"
          style={{ transform: `translate(${view.tx}px, ${view.ty}px) scale(${view.scale})` }}
        >
          {/* Highlighted path overlay (under the cards) */}
          {pathD && (
            <svg className="pointer-events-none absolute inset-0 h-full w-full overflow-visible">
              <path
                d={pathD}
                fill="none"
                stroke="var(--accent-default)"
                strokeWidth={2.5}
                strokeLinejoin="round"
                strokeLinecap="round"
              />
            </svg>
          )}
          <div className="org-tree relative">
            <ul>
              {treeData.map(dept => (
                <OrgChartTree
                  key={dept.id}
                  department={dept}
                  expandedNodes={expandedNodes}
                  selectedId={selectedId}
                  pathIds={pathIds}
                  canEdit={canEdit}
                  onToggle={toggleNode}
                  onSelect={selectNode}
                  onEdit={onDepartmentClick}
                />
              ))}
            </ul>
          </div>
        </div>

        {/* Zoom controls */}
        <div className="absolute bottom-4 left-4 flex items-center gap-1 rounded-lg border border-[var(--border-default)] bg-[var(--surface-base)] p-1 shadow-sm">
          <IconButton size="sm" aria-label={t('hr.zoom_out')} title={t('hr.zoom_out')} onClick={() => zoomButton(1 / 1.2)}>
            <IconMinus className="h-4 w-4" />
          </IconButton>
          <button
            onClick={resetView}
            className="min-w-[3rem] px-1 text-xs font-medium text-[var(--text-secondary)] hover:text-[var(--text-primary)] tabular-nums"
            title={t('hr.reset_view')}
          >
            {Math.round(view.scale * 100)}%
          </button>
          <IconButton size="sm" aria-label={t('hr.zoom_in')} title={t('hr.zoom_in')} onClick={() => zoomButton(1.2)}>
            <IconPlus className="h-4 w-4" />
          </IconButton>
          <IconButton size="sm" aria-label={t('hr.reset_view')} title={t('hr.reset_view')} onClick={resetView}>
            <IconFocusCentered className="h-4 w-4" />
          </IconButton>
          <IconButton
            size="sm"
            aria-label={isFullscreen ? t('hr.exit_fullscreen') : t('hr.fullscreen')}
            title={isFullscreen ? t('hr.exit_fullscreen') : t('hr.fullscreen')}
            onClick={toggleFullscreen}
          >
            {isFullscreen ? <IconArrowsMinimize className="h-4 w-4" /> : <IconArrowsMaximize className="h-4 w-4" />}
          </IconButton>
        </div>
      </div>
    </div>
  );
};

export default OrgChart;
