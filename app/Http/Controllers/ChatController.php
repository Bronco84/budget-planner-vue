<?php

namespace App\Http\Controllers;

use App\Services\ChatService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

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

        $response = $this->chatService->processMessage(
            user: $request->user(),
            message: $validated['message'],
            conversationId: $validated['conversation_id'] ?? null,
            budgetId: $validated['budget_id'] ?? null
        );

        if (!$response['success']) {
            return response()->json($response, 500);
        }

        return response()->json($response);
    }

    public function stream(Request $request)
    {
        $validated = $request->validate([
            'message' => 'required|string|max:2000',
            'conversation_id' => 'nullable|integer|exists:chat_conversations,id',
            'budget_id' => 'nullable|integer|exists:budgets,id',
        ]);

        return $this->chatService->processMessageStream(
            user: $request->user(),
            message: $validated['message'],
            conversationId: $validated['conversation_id'] ?? null,
            budgetId: $validated['budget_id'] ?? null
        );
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
        $conversation = \App\Models\ChatConversation::where('user_id', $request->user()->id)
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
}
