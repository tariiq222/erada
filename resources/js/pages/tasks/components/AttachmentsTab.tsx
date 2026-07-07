import React from 'react';
import { useTranslation } from 'react-i18next';
import { formatDate } from '@shared/lib/utils';
import { EmptyState } from '@shared/ui';
import {IconPaperclip, IconFileText, IconPhoto, IconMovie, IconFile, IconDownload, IconCalendar, IconUser} from '@tabler/icons-react';
import type { Comment } from './types';

interface AttachmentsTabProps {
  comments: Comment[];
}

const getAttachmentIcon = (fileType: string) => {
  if (fileType?.startsWith('image/')) return IconPhoto;
  if (fileType?.startsWith('video/')) return IconMovie;
  if (fileType?.includes('pdf') || fileType?.includes('document')) return IconFileText;
  return IconFile;
};

const formatRelativeDate = (dateString: string, t: (key: string, options?: Record<string, unknown>) => string) => {
  const date = new Date(dateString);
  const now = new Date();
  const diff = now.getTime() - date.getTime();
  const minutes = Math.floor(diff / 60000);
  const hours = Math.floor(diff / 3600000);
  const days = Math.floor(diff / 86400000);

  if (minutes < 1) return t('tasks.time_now');
  if (minutes < 60) return t('tasks.time_minutes_ago', { count: minutes });
  if (hours < 24) return t('tasks.time_hours_ago', { count: hours });
  if (days < 7) return t('tasks.time_days_ago', { count: days });
  return formatDate(date);
};

const AttachmentsTab: React.FC<AttachmentsTabProps> = ({ comments }) => {
  const { t } = useTranslation();
  const allAttachments = comments?.flatMap(comment =>
    (comment.attachments || []).map(attachment => ({
      ...attachment,
      uploadedBy: comment.user.name,
      uploadedAt: comment.created_at,
    }))
  ) || [];

  if (allAttachments.length === 0) {
    return (
      <EmptyState
        icon={IconPaperclip}
        title={t('tasks.no_attachments')}
        description={t('tasks.attachments_from_comments')}
        size="md"
      />
    );
  }

  return (
    <div className="space-y-3">
      {allAttachments.map((attachment, index) => {
        const AttachmentIcon = getAttachmentIcon(attachment.file_type);
        return (
          <div
            key={`${attachment.id}-${index}`}
            className="flex items-center gap-4 p-4 bg-[var(--surface-base)] rounded-xl border border-[var(--border-default)] hover:border-[var(--accent-subtle)] transition-colors"
          >
            <div className="p-3 bg-[var(--surface-muted)] rounded-lg">
              <AttachmentIcon className="h-6 w-6 text-[var(--text-secondary)]" />
            </div>
            <div className="flex-1 min-w-0">
              <p className="font-medium text-[var(--text-primary)] truncate">
                {attachment.name}
              </p>
              <div className="flex items-center gap-4 mt-1 text-xs text-[var(--text-secondary)]">
                <span className="flex items-center gap-1">
                  <IconUser className="h-3 w-3" />
                  {attachment.uploadedBy}
                </span>
                <span className="flex items-center gap-1">
                  <IconCalendar className="h-3 w-3" />
                  {formatRelativeDate(attachment.uploadedAt, t)}
                </span>
                {attachment.formatted_size && (
                  <span>{attachment.formatted_size}</span>
                )}
              </div>
            </div>
            <a
              href={attachment.url}
              target="_blank"
              rel="noopener noreferrer"
              className="p-2 text-[var(--text-tertiary)] hover:text-[var(--accent-default)] hover:bg-[var(--accent-subtle)] rounded-lg transition-colors"
              title={t('tasks.download_file')}
              aria-label={t('tasks.download_file')}
            >
              <IconDownload className="h-5 w-5" />
            </a>
          </div>
        );
      })}
    </div>
  );
};

export default AttachmentsTab;
