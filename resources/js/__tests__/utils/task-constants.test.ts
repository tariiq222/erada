import { describe, it, expect } from 'vitest';

import {
  statusOptions,
  priorityOptions,
  emptyTaskFormData,
  emptyMilestoneFormData,
} from '@pages/tasks/form/constants';

describe('Task Form Constants - statusOptions', () => {
  it('has 4 status options', () => {
    expect(statusOptions.length).toBe(4);
  });

  it('has todo option', () => {
    expect(statusOptions.some(o => o.value === 'todo')).toBe(true);
  });

  it('has in_progress option', () => {
    expect(statusOptions.some(o => o.value === 'in_progress')).toBe(true);
  });

  it('has in_review option', () => {
    expect(statusOptions.some(o => o.value === 'in_review')).toBe(true);
  });

  it('has completed option', () => {
    expect(statusOptions.some(o => o.value === 'completed')).toBe(true);
  });

  it('has Arabic labels', () => {
    const todoOption = statusOptions.find(o => o.value === 'todo');
    expect(todoOption?.labelKey).toBe('status.todo');
  });
});

describe('Task Form Constants - priorityOptions', () => {
  it('has 4 priority options', () => {
    expect(priorityOptions.length).toBe(4);
  });

  it('has low option', () => {
    expect(priorityOptions.some(o => o.value === 'low')).toBe(true);
  });

  it('has medium option', () => {
    expect(priorityOptions.some(o => o.value === 'medium')).toBe(true);
  });

  it('has high option', () => {
    expect(priorityOptions.some(o => o.value === 'high')).toBe(true);
  });

  it('has urgent option', () => {
    expect(priorityOptions.some(o => o.value === 'urgent')).toBe(true);
  });

  it('has Arabic labels', () => {
    const lowOption = priorityOptions.find(o => o.value === 'low');
    expect(lowOption?.labelKey).toBe('priority.low');
  });
});

describe('Task Form Constants - emptyTaskFormData', () => {
  it('has empty project_id', () => {
    expect(emptyTaskFormData.project_id).toBe('');
  });

  it('has empty milestone_id', () => {
    expect(emptyTaskFormData.milestone_id).toBe('');
  });

  it('has empty parent_id', () => {
    expect(emptyTaskFormData.parent_id).toBe('');
  });

  it('has empty assigned_to', () => {
    expect(emptyTaskFormData.assigned_to).toBe('');
  });

  it('has empty title', () => {
    expect(emptyTaskFormData.title).toBe('');
  });

  it('has empty description', () => {
    expect(emptyTaskFormData.description).toBe('');
  });

  it('has default status as todo', () => {
    expect(emptyTaskFormData.status).toBe('todo');
  });

  it('has default priority as medium', () => {
    expect(emptyTaskFormData.priority).toBe('medium');
  });

  it('has empty start_date', () => {
    expect(emptyTaskFormData.start_date).toBe('');
  });

  it('has empty due_date', () => {
    expect(emptyTaskFormData.due_date).toBe('');
  });

  it('has empty estimated_hours', () => {
    expect(emptyTaskFormData.estimated_hours).toBe('');
  });
});

describe('Task Form Constants - emptyMilestoneFormData', () => {
  it('has empty name', () => {
    expect(emptyMilestoneFormData.name).toBe('');
  });

  it('has empty description', () => {
    expect(emptyMilestoneFormData.description).toBe('');
  });

  it('has empty duration_value', () => {
    expect(emptyMilestoneFormData.duration_value).toBe('');
  });

  it('has default duration_unit as day', () => {
    expect(emptyMilestoneFormData.duration_unit).toBe('day');
  });
});
