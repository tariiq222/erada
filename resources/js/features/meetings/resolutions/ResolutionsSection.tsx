import React, { useCallback, useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import {
  Button,
  Card,
  CardContent,
  CardHeader,
  CardTitle,
  EmptyState,
  Modal,
  ModalBody,
  Skeleton,
} from '@shared/ui';
import { IconClipboardCheck, IconPlus } from '@shared/ui/icons';
import { resolutionsApi } from './api';
import type { MeetingResolution } from './types';
import ResolutionCard from './ResolutionCard';
import ResolutionForm from './ResolutionForm';

export interface ResolutionsSectionProps {
  meetingId: number;
  permissions: {
    canView: boolean;
    canCreate: boolean;
    canUpdate: boolean;
    canDelete: boolean;
    canStart: boolean;
    canHold: boolean;
    canReleaseHold: boolean;
    canConvertToTasks: boolean;
    canComplete: boolean;
    canCancel: boolean;
  };
}

interface Paginated<T> {
  data: T[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

const isPaginated = (v: unknown): v is Paginated<MeetingResolution> =>
  typeof v === 'object' &&
  v !== null &&
  'data' in v &&
  Array.isArray((v as { data: unknown }).data);

const ResolutionsSection: React.FC<ResolutionsSectionProps> = ({
  meetingId,
  permissions,
}) => {
  const { t } = useTranslation();
  const [resolutions, setResolutions] = useState<MeetingResolution[]>([]);
  const [loading, setLoading] = useState(true);
  const [showForm, setShowForm] = useState(false);
  const [editing, setEditing] = useState<MeetingResolution | null>(null);

  const fetch = useCallback(async () => {
    setLoading(true);
    try {
      const res = await resolutionsApi.listForMeeting(meetingId, {
        per_page: 50,
      });
      const raw = res as unknown;
      const list: MeetingResolution[] = Array.isArray(raw)
        ? (raw as MeetingResolution[])
        : isPaginated(raw)
          ? raw.data
          : [];
      setResolutions(list);
    } catch (err) {
      console.error('Failed to fetch resolutions:', err);
      setResolutions([]);
    } finally {
      setLoading(false);
    }
  }, [meetingId]);

  useEffect(() => {
    if (permissions.canView) {
      fetch();
    } else {
      setLoading(false);
      setResolutions([]);
    }
  }, [fetch, permissions.canView]);

  const counts = {
    recommendations: resolutions.filter((r) => r.kind === 'recommendation').length,
    decisions: resolutions.filter((r) => r.kind === 'decision').length,
    active: resolutions.filter(
      (r) => r.status === 'open' || r.status === 'in_progress',
    ).length,
  };

  const openCreate = () => {
    setEditing(null);
    setShowForm(true);
  };

  const openEdit = (r: MeetingResolution) => {
    setEditing(r);
    setShowForm(true);
  };

  const closeForm = () => {
    setShowForm(false);
    setEditing(null);
  };

  const handleSuccess = (_r: MeetingResolution) => {
    closeForm();
    fetch();
  };

  return (
    <Card>
      <CardHeader>
        <div className="flex flex-wrap items-center justify-between gap-2">
          <CardTitle>
            {t('meetings.resolution.section.header', {
              defaultValue: 'قرارات وتوصيات الاجتماع',
            })}
            {resolutions.length > 0 && (
              <span className="ms-2 inline-flex items-center rounded-full bg-[var(--surface-muted)] px-2 py-0.5 text-xs tabular-nums text-[var(--text-secondary)]">
                {resolutions.length}
              </span>
            )}
          </CardTitle>
          {permissions.canCreate && (
            <Button
              size="sm"
              leftIcon={<IconPlus className="h-4 w-4" />}
              onClick={openCreate}
            >
              {t('meetings.resolution.section.new_button', {
                defaultValue: 'إضافة قرار/توصية',
              })}
            </Button>
          )}
        </div>
        {resolutions.length > 0 && (
          <p className="mt-1 text-xs text-[var(--text-tertiary)]">
            {t('meetings.resolution.section.summary', {
              defaultValue:
                '{{recommendations}} توصية · {{decisions}} قرار · {{active}} نشط',
              recommendations: counts.recommendations,
              decisions: counts.decisions,
              active: counts.active,
            })}
          </p>
        )}
      </CardHeader>
      <CardContent>
        {!permissions.canView ? (
          <EmptyState
            icon={IconClipboardCheck}
            title={t('meetings.resolution.section.no_view', {
              defaultValue: 'لا تملك صلاحية عرض القرارات',
            })}
          />
        ) : loading ? (
          <Skeleton className="h-24 w-full" />
        ) : resolutions.length === 0 ? (
          <EmptyState
            icon={IconClipboardCheck}
            title={t('meetings.resolution.section.empty', {
              defaultValue: 'لا توجد قرارات أو توصيات بعد',
            })}
            description={t('meetings.resolution.section.empty_hint', {
              defaultValue:
                'أضف قرارًا أو توصية لربطها بهذا الاجتماع ومتابعتها عبر دورة الحياة.',
            })}
            action={
              permissions.canCreate ? (
                <Button
                  leftIcon={<IconPlus className="h-4 w-4" />}
                  onClick={openCreate}
                >
                  {t('meetings.resolution.section.create_cta', {
                    defaultValue: 'أنشئ أول قرار',
                  })}
                </Button>
              ) : undefined
            }
          />
        ) : (
          <div className="grid grid-cols-1 gap-3">
            {resolutions.map((r) => (
              <ResolutionCard
                key={r.id}
                resolution={r}
                permissions={permissions}
                onChanged={fetch}
                onEdit={openEdit}
              />
            ))}
          </div>
        )}
      </CardContent>

      <Modal
        open={showForm}
        onClose={closeForm}
        size="lg"
        title={
          editing
            ? t('meetings.resolution.form.edit_title', { defaultValue: 'تعديل القرار' })
            : t('meetings.resolution.form.create_title', { defaultValue: 'قرار جديد' })
        }
      >
        <ModalBody>
          <ResolutionForm
            mode="modal"
            meetingId={meetingId}
            initial={editing ?? undefined}
            onSuccess={handleSuccess}
            onCancel={closeForm}
          />
        </ModalBody>
      </Modal>
    </Card>
  );
};

export default ResolutionsSection;