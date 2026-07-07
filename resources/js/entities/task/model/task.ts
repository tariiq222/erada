/**
 * Task entity — domain model types.
 *
 * The request payload types currently live in the shared lib/api types module.
 * Re-export them here so the task slice exposes its own model surface while
 * the canonical definition stays in one place.
 */
export type { CreateTaskRequest, CreateUnifiedTaskRequest } from '@shared/api/types';
