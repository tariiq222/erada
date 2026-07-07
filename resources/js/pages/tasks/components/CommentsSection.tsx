import { useState, useEffect, memo } from 'react';
import { useTranslation } from 'react-i18next';
import { commentsApi } from '@entities/comment';
import { usersApi } from '@entities/user';
import { formatDate } from '@shared/lib/utils';
import { useAuth } from '@shared/contexts/AuthContext';
import { IconButton } from '@shared/ui/IconButton';
import {IconTrash, IconAt, IconMessage, IconFileText, IconPhoto, IconMovie, IconFile} from '@tabler/icons-react';
import { MentionInput, type UserOption } from '@shared/ui';
import type { Comment } from './types';

export interface CommentsSectionProps {
  taskId: number;
  initialComments: Comment[];
  onCommentsCountChange?: (count: number) => void;
}

const CommentsSection = memo<CommentsSectionProps>(({
  taskId,
  initialComments,
  onCommentsCountChange,
}) => {
  const { t } = useTranslation();
  const { user } = useAuth();
  const [comments, setComments] = useState<Comment[]>(initialComments);
  const [newComment, setNewComment] = useState('');
  const [attachments, setAttachments] = useState<File[]>([]);
  const [submitting, setSubmitting] = useState(false);
  const [users, setUsers] = useState<UserOption[]>([]);
  const [deletingId, setDeletingId] = useState<number | null>(null);

  // تحديث التعليقات عند تغيير initialComments (عند فتح مهمة جديدة)
  useEffect(() => {
    setComments(initialComments);
  }, [taskId]); // فقط عند تغيير المهمة

  useEffect(() => {
    usersApi.getList().then((response: any) => {
      setUsers(response.data || response || []);
    }).catch(console.error);
  }, []);

  const extractMentionedUsers = (content: string): number[] => {
    const mentionedNames = content.match(/@([^\s@]+)/g)?.map(m => m.slice(1)) || [];
    return users
      .filter(u => mentionedNames.includes(u.name))
      .map(u => u.id);
  };

  const handleSubmit = async () => {
    if (!newComment.trim() && attachments.length === 0) return;

    setSubmitting(true);
    try {
      const mentionedUserIds = extractMentionedUsers(newComment);
      const response = await commentsApi.create({
        commentable_type: 'task',
        commentable_id: taskId,
        content: newComment || ' ',
        mentioned_users: mentionedUserIds,
        attachments: attachments.length > 0 ? attachments : undefined,
      });

      // إضافة التعليق الجديد محلياً بدون refresh
      const newCommentData = (response as any).comment || response;
      if (newCommentData) {
        setComments(prev => [newCommentData, ...prev]);
        onCommentsCountChange?.(comments.length + 1);
      }

      setNewComment('');
      setAttachments([]);
    } catch (error) {
      console.error('Failed to add comment:', error);
    } finally {
      setSubmitting(false);
    }
  };

  const handleDelete = async (commentId: number) => {
    if (!confirm(t('tasks.confirm_delete_comment'))) return;

    setDeletingId(commentId);
    try {
      await commentsApi.delete(commentId);
      // حذف التعليق محلياً بدون refresh
      setComments(prev => prev.filter(c => c.id !== commentId));
      onCommentsCountChange?.(comments.length - 1);
    } catch (error) {
      console.error('Failed to delete comment:', error);
    } finally {
      setDeletingId(null);
    }
  };

  const renderCommentContent = (content: string) => {
    const parts = content.split(/(@[^\s@]+)/g);
    return parts.map((part, index) => {
      if (part.startsWith('@')) {
        return (
          <span
            key={index}
            className="inline-flex items-center gap-0 px-1 py-0 bg-[var(--accent-subtle)] text-[var(--accent-default)] rounded-md font-medium text-sm"
          >
            <IconAt className="h-3 w-3" />
            {part.slice(1)}
          </span>
        );
      }
      return <span key={index}>{part}</span>;
    });
  };

  const formatRelativeDate = (dateString: string) => {
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

  const getAttachmentIcon = (fileType: string) => {
    if (fileType?.startsWith('image/')) return IconPhoto;
    if (fileType?.startsWith('video/')) return IconMovie;
    if (fileType?.includes('pdf') || fileType?.includes('document')) return IconFileText;
    return IconFile;
  };

  return (
    <div className="space-y-4">
      {/* New Comment Input */}
      <MentionInput
        value={newComment}
        onChange={setNewComment}
        onSubmit={handleSubmit}
        users={users}
        disabled={submitting}
        placeholder={t('tasks.comment_placeholder')}
        attachments={attachments}
        onAttachmentsChange={setAttachments}
      />

      {/* Comments List */}
      <div className="space-y-3 max-h-[400px] overflow-y-auto">
        {comments.length === 0 ? (
          <div className="text-center py-8">
            <IconMessage className="h-10 w-10 text-[var(--text-tertiary)] mx-auto mb-2" />
            <p className="text-[var(--text-tertiary)] text-sm">{t('tasks.no_comments_yet')}</p>
          </div>
        ) : (
          comments.map((comment) => (
            <div
              key={comment.id}
              className="group relative bg-[var(--surface-subtle)] rounded-xl p-3 hover:bg-[var(--surface-muted)] transition-colors"
            >
              <div className="flex items-start gap-3">
                <div className="h-8 w-8 rounded-full bg-[var(--accent-default)] flex items-center justify-center shrink-0">
                  <span className="text-[var(--text-inverse)] text-xs font-bold">
                    {comment.user.name.charAt(0)}
                  </span>
                </div>

                <div className="flex-1 min-w-0">
                  <div className="flex items-center justify-between gap-2 mb-1">
                    <span className="font-semibold text-[var(--text-primary)] text-sm">
                      {comment.user.name}
                    </span>
                    <span className="text-xs text-[var(--text-tertiary)]">
                      {formatRelativeDate(comment.created_at)}
                    </span>
                  </div>
                  <p className="text-[var(--text-primary)] text-sm leading-relaxed whitespace-pre-wrap">
                    {renderCommentContent(comment.content)}
                  </p>

                  {/* Attachments */}
                  {comment.attachments && comment.attachments.length > 0 && (
                    <div className="mt-2 space-y-1">
                      <div className="flex flex-wrap gap-2">
                        {comment.attachments.map((attachment) => {
                          const AttachmentIcon = getAttachmentIcon(attachment.file_type);
                          return (
                            <a
                              key={attachment.id}
                              href={attachment.url}
                              target="_blank"
                              rel="noopener noreferrer"
                              className="flex items-center gap-1 px-2 py-1 bg-[var(--surface-base)] border border-[var(--border-default)] rounded-lg text-xs hover:border-[var(--accent-subtle)] transition-colors"
                            >
                              <AttachmentIcon className="h-3.5 w-3.5 text-[var(--text-tertiary)]" />
                              <span className="max-w-24 truncate text-[var(--text-primary)]">{attachment.name}</span>
                            </a>
                          );
                        })}
                      </div>
                    </div>
                  )}
                </div>

                {user?.id === comment.user.id && (
                  <IconButton
                    variant="danger"
                    size="none"
                    onClick={() => handleDelete(comment.id)}
                    disabled={deletingId === comment.id}
                    aria-label={t('common.delete')}
                    className="opacity-0 group-hover:opacity-100 p-1 rounded transition-opacity"
                  >
                    <IconTrash className="h-3.5 w-3.5" />
                  </IconButton>
                )}
              </div>
            </div>
          ))
        )}
      </div>
    </div>
  );
});

CommentsSection.displayName = 'CommentsSection';

export default CommentsSection;
