<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Models\Job;
use App\Models\JobMessage;
use App\Models\User;
use App\Services\Mobile\ElectricianJobAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * A job's own conversation, for everyone who can see the job. Reading it marks
 * it read for the reader, which is what the "new messages" count on the job
 * screen is measured against.
 */
class JobMessageController extends Controller
{
    use ApiResponses;

    public function __construct(private readonly ElectricianJobAccess $access) {}

    /** Newest 100 by default; `after` returns only what arrived since that message id. */
    public function index(Request $request, Job $job): JsonResponse
    {
        abort_unless($this->access->canAccess($request->user(), $job), 403, 'You are not staffed on this job.');

        $after = $request->integer('after');

        $messages = $job->messages()
            ->with('sender:id,name,role')
            ->when($after > 0, fn ($q) => $q->where('id', '>', $after))
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->reverse()
            ->values();

        // Viewing the thread is what reads it.
        if ($messages->isNotEmpty()) {
            $this->markRead($request->user(), $job, (int) $messages->last()->id);
        }

        return $this->ok([
            'messages' => $messages->map(fn (JobMessage $m) => $this->present($m, $request->user()))->all(),
            'unread' => $job->unreadMessageCountFor($request->user()),
        ]);
    }

    public function store(Request $request, Job $job): JsonResponse
    {
        abort_unless($this->access->canAccess($request->user(), $job), 403, 'You are not staffed on this job.');

        $data = $request->validate(['body' => ['required', 'string', 'max:2000']]);

        $message = $job->messages()->create([
            'user_id' => $request->user()->id,
            'body' => trim($data['body']),
        ]);
        $message->load('sender:id,name,role');

        // Your own message is never "new" to you.
        $this->markRead($request->user(), $job, (int) $message->id);

        return $this->created($this->present($message, $request->user()));
    }

    private function markRead(User $user, Job $job, int $upToId): void
    {
        DB::table('job_message_reads')->upsert(
            [[
                'job_id' => $job->id,
                'user_id' => $user->id,
                'last_read_message_id' => $upToId,
                'created_at' => now(),
                'updated_at' => now(),
            ]],
            ['job_id', 'user_id'],
            // Never move the marker backwards.
            ['last_read_message_id' => DB::raw("GREATEST(last_read_message_id, {$upToId})"), 'updated_at' => now()],
        );
    }

    /** @return array<string, mixed> */
    private function present(JobMessage $message, User $viewer): array
    {
        return [
            'id' => $message->id,
            'body' => $message->body,
            'senderId' => $message->user_id,
            'senderName' => $message->sender?->name ?? 'Unknown',
            'senderRole' => $message->sender?->role,
            'isMine' => $message->user_id === $viewer->id,
            'createdAt' => $message->created_at?->toISOString(),
        ];
    }
}
