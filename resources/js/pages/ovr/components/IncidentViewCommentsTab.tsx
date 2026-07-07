import React from 'react';
import { useTranslation } from 'react-i18next';
import { formatDate } from '@shared/lib/utils';
import type { Comment } from './types';

const COMMENT_DATE_FORMAT: Intl.DateTimeFormatOptions = {
  year: 'numeric',
  month: 'short',
  day: 'numeric',
  hour: '2-digit',
  minute: '2-digit',
};

interface CommentsTabProps {
  comments: Comment[];
}

const IncidentViewCommentsTab: React.FC<CommentsTabProps> = ({ comments }) => {
  const { t } = useTranslation();

  if (comments.length === 0) {
    return (
      <p className="text-sm text-[var(--text-secondary)] text-center py-4">
        {t('ovr.no_comments')}
      </p>
    );
  }

  return (
    <div className="space-y-3 pt-2 max-h-80 overflow-y-auto">
      {comments.map((comment) => (
        <div key={comment.id} className="p-3 bg-[var(--surface-subtle)] rounded-lg">
          <div className="flex items-center justify-between mb-1">
            <span className="text-sm font-medium">
              {comment.user?.name || t('common.unknown')}
            </span>
            <span className="text-xs text-[var(--text-tertiary)]">
              {formatDate(comment.created_at, COMMENT_DATE_FORMAT)}
            </span>
          </div>
          <p className="text-sm text-[var(--text-primary)]">{comment.content}</p>
        </div>
      ))}
    </div>
  );
};

export default IncidentViewCommentsTab;