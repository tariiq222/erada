export const typeLabels: Record<string, string> = {
  initial: 'surveys.type_initial',
  periodic: 'surveys.type_periodic',
};

export const typeVariants: Record<string, 'accent' | 'warning'> = {
  initial: 'accent',
  periodic: 'warning',
};

export const statusLabels: Record<string, string> = {
  draft: 'status.draft',
  published: 'surveys.published',
  closed: 'surveys.closed',
  archived: 'surveys.archived',
};

export const statusVariants: Record<string, 'primary' | 'secondary' | 'success' | 'warning' | 'danger' | 'info'> = {
  draft: 'secondary',
  published: 'success',
  closed: 'warning',
  archived: 'secondary',
};
