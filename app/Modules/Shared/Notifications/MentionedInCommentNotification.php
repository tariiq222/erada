<?php

namespace App\Modules\Shared\Notifications;

use App\Modules\Core\Models\User;
use App\Modules\Shared\Models\Comment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MentionedInCommentNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected Comment $comment;

    protected User $mentionedBy;

    protected string $contextType;

    protected int $contextId;

    protected string $contextName;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        Comment $comment,
        User $mentionedBy,
        string $contextType,
        int $contextId,
        string $contextName
    ) {
        $this->comment = $comment;
        $this->mentionedBy = $mentionedBy;
        $this->contextType = $contextType;
        $this->contextId = $contextId;
        $this->contextName = $contextName;
    }

    /**
     * Get the notification's delivery channels.
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $contextLabel = $this->contextType === 'project' ? 'المشروع' : 'المهمة';
        $url = $this->contextType === 'project'
            ? url("/projects/{$this->contextId}")
            : url("/tasks/{$this->contextId}");

        return (new MailMessage)
            ->subject('تم ذكرك في تعليق')
            ->greeting("مرحباً {$notifiable->name}")
            ->line("قام {$this->mentionedBy->name} بذكرك في تعليق.")
            ->line("{$contextLabel}: {$this->contextName}")
            ->line('التعليق:')
            ->line('"'.mb_substr($this->comment->content, 0, 200).(mb_strlen($this->comment->content) > 200 ? '...' : '').'"')
            ->action('عرض التعليق', $url)
            ->line('شكراً لاستخدامك منصة إرادة');
    }

    /**
     * Get the array representation of the notification.
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'mention_in_comment',
            'comment_id' => $this->comment->id,
            'mentioned_by_id' => $this->mentionedBy->id,
            'mentioned_by_name' => $this->mentionedBy->name,
            'context_type' => $this->contextType,
            'context_id' => $this->contextId,
            'context_name' => $this->contextName,
            'content_preview' => mb_substr($this->comment->content, 0, 100),
            'message' => "قام {$this->mentionedBy->name} بذكرك في تعليق على {$this->contextName}",
        ];
    }
}
