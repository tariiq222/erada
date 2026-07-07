/**
 * Project entity — domain model types.
 *
 * The request payload types currently live in the shared lib/api types module.
 * Re-export them here so the project slice exposes its own model surface while
 * the canonical definition stays in one place.
 */
export type { CreateProjectRequest } from '@shared/api/types';
