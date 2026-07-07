import React, { useState, useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import { formatDate } from '@shared/lib/utils';
import { commentsApi } from '@entities/comment';
import { usersApi } from '@entities/user';
import { useAuth } from '@shared/contexts/AuthContext';
import {IconTrash, IconAt, IconPaperclip, IconFileText, IconPhoto, IconMovie, IconFile, IconDownload, IconMessage} from '@tabler/icons-react';
import { MentionInput, type UserOption, EmptyState } from '@shared/ui';
import { IconButton } from '@shared/ui/IconButton';
import type { Comment } from './types';

interface CommentsSectionProps {
  taskId: number;
  comments: Comment[];
  onCommentAdded: () => void;
}

const CommentsSection: React.FC<CommentsSectionProps> = ({
  taskId,
  comments,
  onCommentAdded,
}) => {
  const { t } = useTranslation();
  const { user } = useAuth();
  const [newComment, setNewComment] = useState('');
  const [attachments, setAttachments] = useState<File[]>([]);
  const [submitting, setSubmitting] = useState(false);
  const [users, setUsers] = useState<UserOption[]>([]);
  const [deletingId, setDeletingId] = useState<number | null>(null);
  const [deletingAttachmentId, setDeletingAttachmentId] = useState<number | null>(null);

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
      await commentsApi.create({
        commentable_type: 'task',
        commentable_id: taskId,
        content: newComment || ' ',
        mentioned_users: mentionedUserIds,
        attachments: attachments.length > 0 ? attachments : undefined,
      });
      setNewComment('');
      setAttachments([]);
      onCommentAdded();
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
      onCommentAdded();
    } catch (error) {
      console.error('Failed to delete comment:', error);
    } finally {
      setDeletingId(null);
    }
  };

  const handleDeleteAttachment = async (commentId: number, attachmentId: number) => {
    if (!confirm(t('tasks.confirm_delete_attachment'))) return;

    setDeletingAttachmentId(attachmentId);
    try {
      await commentsApi.deleteAttachment(commentId, attachmentId);
      onCommentAdded();
    } catch (error) {
      console.error('Failed to delete attachment:', error);
    } finally {
      setDeletingAttachmentId(null);
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

  const isImageFile = (fileType: string) => fileType?.startsWith('image/');

  return (
    <div className="space-y-6">
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
      <div className="space-y-4">
        {comments.length === 0 ? (
          <EmptyState
            icon={IconMessage}
            title={t('tasks.no_comments_yet')}
            description={t('tasks.be_first_to_comment')}
            size="md"
          />
        ) : (
          comments.map((comment) => (
            <div
              key={comment.id}
              className="group relative bg-[var(--surface-subtle)] rounded-xl p-4 hover:bg-[var(--surface-muted)]/80 transition-colors"
            >
              <div className="flex items-start gap-3">
                <div className="h-10 w-10 rounded-full bg-[var(--accent-default)] flex items-center justify-center shrink-0">
                  <span className="text-[var(--text-inverse)] text-sm font-bold">
                    {comment.user.name.charAt(0)}
                  </span>
                </div>

                <div className="flex-1 min-w-0">
                  <div className="flex items-center justify-between gap-2 mb-1">
                    <span className="font-semibold text-[var(--text-primary)]">
                      {comment.user.name}
                    </span>
                    <span className="text-xs text-[var(--text-secondary)]">
                      {formatRelativeDate(comment.created_at)}
                    </span>
                  </div>
                  <p className="text-[var(--text-primary)] text-sm leading-relaxed whitespace-pre-wrap">
                    {renderCommentContent(comment.content)}
                  </p>

                  {/* Attachments */}
                  {comment.attachments && comment.attachments.length > 0 && (
                    <div className="mt-3 space-y-2">
                      <div className="text-xs text-[var(--text-secondary)] flex items-center gap-1">
                        <IconPaperclip className="h-3 w-3" />
                        {t('tasks.attachments')} ({comment.attachments.length})
                      </div>
                      <div className="grid grid-cols-2 sm:grid-cols-3 gap-2">
                        {comment.attachments.map((attachment) => {
                          const AttachmentIcon = getAttachmentIcon(attachment.file_type);
                          return (
                            <div
                              key={attachment.id}
                              className="relative group/attachment bg-[var(--surface-base)] border border-[var(--border-default)] rounded-lg overflow-hidden"
                            >
                              {isImageFile(attachment.file_type) ? (
                                <a
                                  href={attachment.url}
                                  target="_blank"
                                  rel="noopener noreferrer"
                                  className="block"
                                >
                                  <img
                                    src={attachment.url}
                                    alt={attachment.name}
                                    className="w-full h-20 object-cover"
                                  />
                                  <div className="p-2">
                                    <p className="text-xs text-[var(--text-primary)] truncate">{attachment.name}</p>
                                    <p className="text-xs text-[var(--text-tertiary)]">{attachment.formatted_size}</p>
                                  </div>
                                </a>
                              ) : (
                                <a
                                  href={attachment.url}
                                  target="_blank"
                                  rel="noopener noreferrer"
                                  className="flex items-center gap-2 p-3"
                                >
                                  <AttachmentIcon className="h-8 w-8 text-[var(--text-tertiary)] shrink-0" />
                                  <div className="min-w-0">
                                    <p className="text-xs text-[var(--text-primary)] truncate">{attachment.name}</p>
                                    <p className="text-xs text-[var(--text-tertiary)]">{attachment.formatted_size}</p>
                                  </div>
                                </a>
                              )}

                              <div className="absolute top-1 left-1 flex gap-1 opacity-0 group-hover/attachment:opacity-100 transition-opacity">
                                <a
                                  href={attachment.url}
                                  download={attachment.name}
                                  aria-label={t('tasks.download_file')}
                                  className="p-1 bg-[var(--surface-base)]/90 rounded shadow hover:bg-[var(--surface-base)] transition-colors"
                                >
                                  <IconDownload className="h-3 w-3 text-[var(--text-secondary)]" />
                                </a>
                                {user?.id === comment.user.id && (
                                  <button
                                    onClick={() => handleDeleteAttachment(comment.id, attachment.id)}
                                    disabled={deletingAttachmentId === attachment.id}
                                    aria-label={t('common.delete')}
                                    className="p-1 bg-[var(--surface-base)]/90 rounded shadow hover:bg-[var(--status-danger-subtle)] transition-colors"
                                  >
                                    <IconTrash className="h-3 w-3 text-[var(--status-danger)]" />
                                  </button>
                                )}
                              </div>
                            </div>
                          );
                        })}
                      </div>
                    </div>
                  )}

                  {/* Mentioned Users */}
                  {comment.mentioned_users && comment.mentioned_users.length > 0 && (
                    <div className="flex items-center gap-2 mt-2 pt-2 border-t border-[var(--border-default)]">
                      <span className="text-xs text-[var(--text-secondary)]">{t('tasks.mentioned_users')}:</span>
                      <div className="flex items-center gap-1">
                        {comment.mentioned_users.map((u) => (
                          <span
                            key={u.id}
                            className="inline-flex items-center gap-1 px-2 py-0 bg-[var(--accent-subtle)] text-[var(--accent-default)] rounded-full text-xs"
                          >
                            @{u.name}
                          </span>
                        ))}
                      </div>
                    </div>
                  )}
                </div>

                {user?.id === comment.user.id && (
                  <IconButton
                    variant="danger"
                    size="sm"
                    onClick={() => handleDelete(comment.id)}
                    disabled={deletingId === comment.id}
                    aria-label={t('common.delete')}
                    className="opacity-0 group-hover:opacity-100 transition-opacity"
                  >
                    <IconTrash className="h-4 w-4" />
                  </IconButton>
                )}
              </div>
            </div>
          ))
        )}
      </div>
    </div>
  );
};

export default CommentsSection;
