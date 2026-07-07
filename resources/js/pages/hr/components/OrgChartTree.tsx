import React from 'react';
import { OrgChartNode } from './OrgChartNode';
import { levelLineColor } from './departmentTypes';
import type { TreeDepartment } from './departmentTypes';

interface OrgChartTreeProps {
  department: TreeDepartment;
  expandedNodes: Set<number>;
  selectedId?: number | null;
  pathIds?: Set<number>;
  canEdit?: boolean;
  onToggle: (id: number) => void;
  onSelect?: (id: number) => void;
  onEdit?: (dept: TreeDepartment) => void;
}

// Connectors are drawn purely in CSS (see `.org-tree` in app.css), so this
// component only emits the semantic <li>/<ul> tree and never positions lines.
// Each children <ul> carries `--branch-color` so the branch lines match the
// parent department's level color.
export const OrgChartTree: React.FC<OrgChartTreeProps> = ({
  department,
  expandedNodes,
  selectedId,
  pathIds,
  canEdit,
  onToggle,
  onSelect,
  onEdit,
}) => {
  const isExpanded = expandedNodes.has(department.id);
  const hasChildren = department.children && department.children.length > 0;
  const showChildren = isExpanded && hasChildren;

  return (
    <li>
      <OrgChartNode
        department={department}
        isExpanded={isExpanded}
        isSelected={selectedId === department.id}
        isOnPath={pathIds?.has(department.id)}
        canEdit={canEdit}
        onToggle={onToggle}
        onSelect={onSelect}
        onEdit={onEdit}
      />

      {showChildren && (
        <ul style={{ ['--branch-color' as string]: levelLineColor(department.level) }}>
          {department.children.map((child) => (
            <OrgChartTree
              key={child.id}
              department={child}
              expandedNodes={expandedNodes}
              selectedId={selectedId}
              pathIds={pathIds}
              canEdit={canEdit}
              onToggle={onToggle}
              onSelect={onSelect}
              onEdit={onEdit}
            />
          ))}
        </ul>
      )}
    </li>
  );
};

export default OrgChartTree;
