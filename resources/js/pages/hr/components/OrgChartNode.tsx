import React from 'react';
import { useTranslation } from 'react-i18next';
import {IconChevronDown, IconChevronUp, IconUser, IconUsers, IconBuilding, IconPencil} from '@tabler/icons-react';
import { cn } from '@shared/lib/utils';
import { Badge, IconButton } from '@shared/ui';
import type { TreeDepartment } from './departmentTypes';
import { DEPARTMENT_LEVEL_COLORS, LEVEL_BORDER_COLORS } from './departmentTypes';

interface OrgChartNodeProps {
  department: TreeDepartment;
  isExpanded: boolean;
  isSelected?: boolean;
  isOnPath?: boolean;
  canEdit?: boolean;
  onToggle: (id: number) => void;
  onSelect?: (id: number) => void;
  onEdit?: (dept: TreeDepartment) => void;
}

const getBadgeVariant = (level: number): 'default' | 'success' | 'warning' | 'danger' | 'accent' | 'info' => {
  const colorMap: Record<string, 'default' | 'success' | 'warning' | 'danger' | 'accent' | 'info'> = {
    primary: 'accent',
    info: 'info',
    success: 'success',
    warning: 'warning',
    default: 'default',
    secondary: 'accent',
  };
  return colorMap[DEPARTMENT_LEVEL_COLORS[level]] || 'default';
};

export const OrgChartNode: React.FC<OrgChartNodeProps> = ({
  department,
  isExpanded,
  isSelected,
  isOnPath,
  canEdit,
  onToggle,
  onSelect,
  onEdit,
}) => {
  const { t, i18n } = useTranslation();
  const hasChildren = department.children && department.children.length > 0;
  const accentColor = LEVEL_BORDER_COLORS[department.level] || 'border-t-[var(--border-strong)]';

  return (
    <div
      data-orgnode
      data-node-id={department.id}
      dir={i18n.dir()}
      role="button"
      tabIndex={0}
      onClick={() => onSelect?.(department.id)}
      onKeyDown={(e) => {
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault();
          onSelect?.(department.id);
        }
      }}
      className={cn(
        'group relative w-56 bg-[var(--surface-base)] rounded-lg border border-[var(--border-default)] shadow-sm cursor-pointer transition-shadow hover:shadow-md',
        'border-t-2',
        accentColor,
        isOnPath && !isSelected && 'ring-1 ring-[var(--accent-default)]',
        isSelected && 'ring-2 ring-[var(--accent-default)] border-[var(--accent-default)]'
      )}
    >
      {canEdit && (
        <IconButton
          size="xs"
          aria-label={t('common.edit')}
          title={t('common.edit')}
          onClick={(e) => {
            e.stopPropagation();
            onEdit?.(department);
          }}
          className="absolute top-1.5 left-1.5 opacity-0 group-hover:opacity-100 focus:opacity-100 bg-[var(--surface-base)]"
        >
          <IconPencil className="h-3.5 w-3.5" />
        </IconButton>
      )}
      <div className="p-3">
        {/* Header: Icon + Name */}
        <div className="flex items-start gap-2 mb-2">
          <div className="h-8 w-8 rounded-lg bg-[var(--surface-muted)] flex items-center justify-center flex-shrink-0">
            <IconBuilding className="h-4 w-4 text-[var(--text-secondary)]" />
          </div>
          <div className="flex-1 min-w-0">
            <h4 className="text-sm font-semibold text-[var(--text-primary)] truncate" title={department.name}>
              {department.name}
            </h4>
            {department.code && (
              <span className="text-xs text-[var(--text-secondary)]">{department.code}</span>
            )}
          </div>
        </div>

        {/* Level Badge */}
        <div className="mb-2">
          <Badge variant={getBadgeVariant(department.level)} size="sm">
            {department.level_name}
          </Badge>
        </div>

        {/* Manager & Employees */}
        <div className="space-y-1 text-xs text-[var(--text-secondary)]">
          {department.manager ? (
            <div className="flex items-center gap-1">
              <IconUser className="h-3 w-3" />
              <span className="truncate">{department.manager.name}</span>
            </div>
          ) : (
            <div className="flex items-center gap-1 text-[var(--text-tertiary)]">
              <IconUser className="h-3 w-3" />
              <span>{t('common.not_specified')}</span>
            </div>
          )}
          <div className="flex items-center gap-1">
            <IconUsers className="h-3 w-3" />
            <span>{t('hr.employee_count', { count: department.employees_count })}</span>
          </div>
        </div>

        {/* Expand/Collapse Button */}
        {hasChildren && (
          <button
            onClick={(e) => {
              e.stopPropagation();
              onToggle(department.id);
            }}
            className="mt-2 w-full flex items-center justify-center gap-1 py-1 text-xs text-[var(--text-secondary)] hover:text-[var(--text-primary)] hover:bg-[var(--surface-subtle)] rounded transition-colors"
          >
            {isExpanded ? (
              <>
                <IconChevronUp className="h-3 w-3" />
                <span>{t('hr.collapse')} ({department.children.length})</span>
              </>
            ) : (
              <>
                <IconChevronDown className="h-3 w-3" />
                <span>{t('hr.expand')} ({department.children.length})</span>
              </>
            )}
          </button>
        )}
      </div>
    </div>
  );
};

export default OrgChartNode;
