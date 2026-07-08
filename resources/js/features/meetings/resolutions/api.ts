import { api } from '@shared/api/client';
import type {
  MeetingResolution,
  ResolutionCreatePayload,
  ResolutionUpdatePayload,
  HoldPayload,
  ConvertToTasksPayload,
  ConvertToTasksResponse,
} from './types';

// Same query-string helper used by features/meetings/api.ts so the call
// sites look identical across both modules.
const qs = (
  params?: Record<string, string | number | boolean | undefined>,
): string => {
  if (!params) return '';
  const sp = new URLSearchParams();
  Object.entries(params).forEach(([k, v]) => {
    if (v !== undefined && v !== null && v !== '') sp.append(k, String(v));
  });
  const s = sp.toString();
  return s ? `?${s}` : '';
};

export const resolutionsApi = {
  // ─── Nested under meeting ──────────────────────────────────────────────
  listForMeeting: (
    meetingId: number,
    params?: Record<string, string | number | boolean>,
  ) => api.get<unknown>(`/meetings/${meetingId}/resolutions${qs(params)}`),

  createForMeeting: (
    meetingId: number,
    data: ResolutionCreatePayload,
  ) =>
    api.post<{ message: string; resolution: MeetingResolution }>(
      `/meetings/${meetingId}/resolutions`,
      data,
    ),

  // ─── Flat by id ────────────────────────────────────────────────────────
  list: (params?: Record<string, string | number | boolean>) =>
    api.get<unknown>(`/meeting-resolutions${qs(params)}`),

  get: (id: number) =>
    api.get<MeetingResolution>(`/meeting-resolutions/${id}`),

  update: (id: number, data: ResolutionUpdatePayload) =>
    api.patch<{ message: string; resolution: MeetingResolution }>(
      `/meeting-resolutions/${id}`,
      data,
    ),

  remove: (id: number) =>
    api.delete<{ message: string }>(`/meeting-resolutions/${id}`),

  // ─── Lifecycle transitions ────────────────────────────────────────────
  // NB: NO approve/reject/adopt endpoints — by design. A resolution is
  // either acted on (start → hold → convert-to-tasks → complete) or
  // cancelled. The legacy `recommendations.approve|reject|accept` paths
  // are intentionally NOT mirrored here.

  start: (id: number) =>
    api.post<{ message: string; resolution: MeetingResolution }>(
      `/meeting-resolutions/${id}/start`,
      {},
    ),

  hold: (id: number, payload: HoldPayload) =>
    api.post<{ message: string; resolution: MeetingResolution }>(
      `/meeting-resolutions/${id}/hold`,
      payload,
    ),

  releaseHold: (id: number) =>
    api.post<{ message: string; resolution: MeetingResolution }>(
      `/meeting-resolutions/${id}/release-hold`,
      {},
    ),

  convertToTasks: (id: number, payload: ConvertToTasksPayload) =>
    api.post<ConvertToTasksResponse>(
      `/meeting-resolutions/${id}/convert-to-tasks`,
      payload,
    ),

  complete: (id: number) =>
    api.post<{ message: string; resolution: MeetingResolution }>(
      `/meeting-resolutions/${id}/complete`,
      {},
    ),

  cancel: (id: number) =>
    api.post<{ message: string; resolution: MeetingResolution }>(
      `/meeting-resolutions/${id}/cancel`,
      {},
    ),
};