import React from 'react';
import { useDroppable } from '@dnd-kit/core';

interface DroppableSectionProps {
  sectionId: number | null;
  children: React.ReactNode;
}

export const DroppableSection: React.FC<DroppableSectionProps> = ({ sectionId, children }) => {
  const { setNodeRef, isOver } = useDroppable({
    id: `section-${sectionId ?? 'null'}`,
    data: {
      type: 'section',
      sectionId,
    },
  });

  return (
    <div
      ref={setNodeRef}
      className={`space-y-2 min-h-[60px] rounded-lg transition-colors ${
        isOver ? 'bg-[var(--accent-subtle)] ring-2 ring-[var(--accent-default)] ring-dashed' : ''
      }`}
    >
      {children}
    </div>
  );
};

export default DroppableSection;
