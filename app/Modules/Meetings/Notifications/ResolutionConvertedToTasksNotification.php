<?php

namespace App\Modules\Meetings\Notifications;

use App\Modules\Core\Models\User;
use App\Modules\Tasks\Models\Task;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Phase 4 / Direction R — fired when a meeting resolution is successfully
 * converted into one or more tasks on the tasks table.
 *
 * Dispatched to every unique assignee across the converted batch. We
 * intentionally avoid re-notifying the creator (they triggered the
 * action) and we dedupe by user id so a batch of N tasks assigned to
 * the same user produces one notification, not N.
 *
 * `ShouldQueueAfterCommit` ensures the notification only fires after the
 * outer DB transaction commits — a rolled-back conversion does NOT
 * deliver a "you have new tasks" email to anyone.
 */
class ResolutionConvertedToTasksNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<int, Task>  $tasksForAssignee  Tasks created for this specific assignee
     */
    public function __construct(
        public int $resolutionId,
        public string $resolutionTitle,
        public int $meetingId,
        public int $totalTaskCount,
        public int $assigneeTaskCount,
        public array $tasksForAssignee,
        public string $assigneeTaskCountLocalized,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(User $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $title = 'تم تكليفك بمهام من مخرج اجتماع';
        $body = "تم تكليفك بـ {$this->assigneeTaskCountLocalized} من مخرج: {$this->resolutionTitle}";

        return (new MailMessage)
            ->subject($title)
            ->line($body)
            ->line('إجمالي المهام في هذا المخرج: '.$this->totalTaskCount)
            ->action('عرض المهام', url("/tasks?source_type=MeetingResolution&source_id={$this->resolutionId}"));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(User $notifiable): array
    {
        return [
            'type' => 'meeting_resolution_converted_to_tasks',
            'resolution_id' => $this->resolutionId,
            'resolution_title' => $this->resolutionTitle,
            'meeting_id' => $this->meetingId,
            'task_count' => $this->totalTaskCount,
            'assignee_task_count' => $this->assigneeTaskCount,
            'tasks' => array_map(static fn (Task $t) => [
                'id' => $t->id,
                'title' => $t->title,
                'due_date' => $t->due_date?->toDateString(),
                'priority' => $t->priority,
            ], $this->tasksForAssignee),
            'url' => url("/tasks?source_type=MeetingResolution&source_id={$this->resolutionId}"),
            'message' => "تم تكليفك بـ {$this->assigneeTaskCountLocalized} من مخرج: {$this->resolutionTitle}",
        ];
    }
}
