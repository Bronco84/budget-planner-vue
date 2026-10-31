<?php

namespace App\Http\Controllers;

use App\Models\Budget;
use App\Models\ChatConversation;
use App\Services\ChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class ChatController extends Controller
{
    public function __construct(
        protected ChatService $chatService
    ) {}

    public function send(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'message' => 'required|string|max:2000',
            'conversation_id' => 'nullable|integer|exists:chat_conversations,id',
            'budget_id' => 'nullable|integer|exists:budgets,id',
        ]);

        $this->authorizeConversation($request, $validated['conversation_id'] ?? null);

        $response = $this->chatService->processMessage(
            user: $request->user(),
            message: $validated['message'],
            conversationId: $validated['conversation_id'] ?? null,
            budgetId: $validated['budget_id'] ?? null
        );

        if (! $response['success']) {
            return response()->json($response, $this->failureStatus($response));
        }

        return response()->json($response);
    }

    /**
     * Map a known moderation outcome to its 4xx status (matching the stream
     * endpoint); anything else is a server/provider failure.
     */
    protected function failureStatus(array $response): int
    {
        return match (true) {
            ! empty($response['banned']) => 403,
            isset($response['rate_limit']) => 429,
            ! empty($response['blocked']) => 400,
            default => 500,
        };
    }

    /**
     * Pending streams live this long between POST /chat/stream and the
     * EventSource GET that consumes them.
     */
    protected const PENDING_STREAM_TTL_SECONDS = 120;

    /**
     * Start a streamed reply. The question travels in the POST body (not a
     * URL that servers log); moderation runs here so refusals are readable
     * JSON. The moderated turn is parked under a random, single-use id bound
     * to this user, which the EventSource then opens.
     */
    public function startStream(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'message' => 'required|string|max:2000',
            'conversation_id' => 'nullable|integer|exists:chat_conversations,id',
            'budget_id' => 'nullable|integer|exists:budgets,id',
        ]);

        $this->authorizeConversation($request, $validated['conversation_id'] ?? null);

        $budgetId = $validated['budget_id'] ?? null;
        if ($budgetId !== null) {
            abort_unless($request->user()->can('view', Budget::findOrFail($budgetId)), 403);
        }

        $prepared = $this->chatService->prepareStream(
            user: $request->user(),
            message: $validated['message'],
            conversationId: $validated['conversation_id'] ?? null,
        );

        if ($prepared instanceof JsonResponse) {
            return $prepared;
        }

        $streamId = Str::random(40);

        Cache::put($this->pendingStreamKey($request, $streamId), [
            'message' => $prepared['message'],
            'conversation_id' => $prepared['conversation_id'],
            'budget_id' => $budgetId,
        ], now()->addSeconds(self::PENDING_STREAM_TTL_SECONDS));

        return response()->json(['stream_id' => $streamId]);
    }

    /**
     * Stream the reply for a pending stream started by this user. Unknown,
     * expired, reused or other users' ids return 404.
     */
    public function stream(Request $request, string $streamId)
    {
        $key = $this->pendingStreamKey($request, $streamId);
        $pending = Cache::get($key);

        // Cache::add is atomic, so two requests racing on one id cannot both stream.
        if (! is_array($pending)
            || ! Cache::add("{$key}:claimed", true, self::PENDING_STREAM_TTL_SECONDS)) {
            abort(404);
        }

        Cache::forget($key);

        return $this->chatService->streamReply(
            user: $request->user(),
            message: $pending['message'],
            conversationId: $pending['conversation_id'],
            budgetId: $pending['budget_id']
        );
    }

    protected function pendingStreamKey(Request $request, string $streamId): string
    {
        return "chat_pending_stream:{$request->user()->id}:{$streamId}";
    }

    /**
     * Acknowledge the end of a stream. The assistant reply is persisted
     * server-side while streaming, so any client-supplied text is ignored:
     * accepting it would let a user forge assistant turns that are replayed
     * to the model as conversation history.
     */
    public function streamComplete(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'conversation_id' => 'required|integer|exists:chat_conversations,id',
        ]);

        $this->authorizeConversation($request, $validated['conversation_id']);

        return response()->json([
            'success' => true,
            'conversation_id' => $validated['conversation_id'],
        ]);
    }

    public function conversations(Request $request): JsonResponse
    {
        $conversations = $this->chatService->getConversations(
            user: $request->user(),
            limit: 20
        );

        return response()->json([
            'conversations' => $conversations,
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $conversation = ChatConversation::where('user_id', $request->user()->id)
            ->with('messages')
            ->findOrFail($id);

        return response()->json([
            'conversation' => [
                'id' => $conversation->id,
                'title' => $conversation->title ?? 'New Conversation',
                'messages' => $conversation->messages->map(function ($message) {
                    return [
                        'id' => $message->id,
                        'role' => $message->role,
                        'content' => $message->content,
                        'created_at' => $message->created_at->toIso8601String(),
                    ];
                }),
            ],
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->chatService->deleteConversation(
            user: $request->user(),
            conversationId: $id
        );

        return response()->json([
            'message' => 'Conversation deleted successfully',
        ]);
    }

    public function bulkDestroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'conversation_ids' => 'required|array|min:1',
            'conversation_ids.*' => 'required|integer|exists:chat_conversations,id',
        ]);

        $deletedCount = $this->chatService->bulkDeleteConversations(
            user: $request->user(),
            conversationIds: $validated['conversation_ids']
        );

        return response()->json([
            'message' => "Successfully deleted {$deletedCount} conversation(s)",
            'deleted_count' => $deletedCount,
        ]);
    }

    /**
     * 404 when a supplied conversation id does not belong to the current user.
     */
    protected function authorizeConversation(Request $request, ?int $conversationId): void
    {
        if ($conversationId !== null) {
            ChatConversation::where('user_id', $request->user()->id)->findOrFail($conversationId);
        }
    }
}
