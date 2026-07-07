/**
 * Comment entity — API. التعليقات والمرفقات
 */

import { api } from '@shared/api/client';

export const commentsApi = {
  getAll: (type: 'task' | 'project', id: number) =>
    api.get(`/comments?commentable_type=${type}&commentable_id=${id}`),

  create: async (data: {
    commentable_type: string;
    commentable_id: number;
    content: string;
    mentioned_users?: number[];
    attachments?: File[];
  }) => {
    // إذا كانت هناك مرفقات، استخدم FormData
    if (data.attachments && data.attachments.length > 0) {
      const formData = new FormData();
      formData.append('commentable_type', data.commentable_type);
      formData.append('commentable_id', String(data.commentable_id));
      formData.append('content', data.content);

      if (data.mentioned_users) {
        data.mentioned_users.forEach((id, index) => {
          formData.append(`mentioned_users[${index}]`, String(id));
        });
      }

      data.attachments.forEach((file, index) => {
        formData.append(`attachments[${index}]`, file);
      });

      return api.post('/comments', formData);
    }

    // بدون مرفقات، استخدم JSON
    return api.post('/comments', {
      commentable_type: data.commentable_type,
      commentable_id: data.commentable_id,
      content: data.content,
      mentioned_users: data.mentioned_users,
    });
  },

  update: (id: number, content: string) =>
    api.put(`/comments/${id}`, { content }),

  delete: (id: number) => api.delete(`/comments/${id}`),

  // إضافة مرفقات لتعليق موجود
  addAttachments: async (commentId: number, files: File[]) => {
    const formData = new FormData();
    files.forEach((file, index) => {
      formData.append(`attachments[${index}]`, file);
    });

    return api.post(`/comments/${commentId}/attachments`, formData);
  },

  // حذف مرفق
  deleteAttachment: (commentId: number, attachmentId: number) =>
    api.delete(`/comments/${commentId}/attachments/${attachmentId}`),
};
