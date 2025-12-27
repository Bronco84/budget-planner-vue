# Chat Agent Implementation Guide

This guide provides a comprehensive overview of how to build and extend the chat agent feature in this Laravel + Vue.js application. The system is designed to be agnostic and flexible, allowing for different types of agents with customizable instructions and behaviors.

## Table of Contents

1. [System Architecture](#system-architecture)
2. [Database Schema](#database-schema)
3. [Backend Components](#backend-components)
4. [Frontend Components](#frontend-components)
5. [Agent Configuration](#agent-configuration)
6. [Security & Moderation](#security--moderation)
7. [Extending the System](#extending-the-system)
8. [API Reference](#api-reference)
9. [Best Practices](#best-practices)

## System Architecture

The chat system follows a modular architecture with clear separation of concerns:

```
┌─────────────────┐    ┌─────────────────┐    ┌─────────────────┐
│   Frontend      │    │    Backend      │    │   LLM Service   │
│   (Vue.js)      │◄──►│   (Laravel)     │◄──►│    (Prism)      │
└─────────────────┘    └─────────────────┘    └─────────────────┘
         │                       │
         │                       │
         ▼                       ▼
┌─────────────────┐    ┌─────────────────┐
│   UI Components │    │   Database      │
│   - ChatPanel   │    │   - Messages    │
│   - ChatMessage │    │   - Conversations│
└─────────────────┘    │   - Moderation  │
                       └─────────────────┘
```

### Key Features

- **Real-time streaming responses** using Server-Sent Events (SSE)
- **Conversation management** with persistent storage
- **Content moderation** with automatic flagging and rate limiting
- **Responsive UI** with resizable panels and mobile support
- **Markdown rendering** for rich message formatting
- **Agnostic agent system** supporting different agent types and instructions

## Database Schema

### Core Tables

#### `chat_conversations`
```sql
CREATE TABLE chat_conversations (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    user_id BIGINT NOT NULL,
    title VARCHAR(255) NULL,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
```

#### `chat_messages`
```sql
CREATE TABLE chat_messages (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    conversation_id BIGINT NOT NULL,
    role ENUM('user', 'assistant', 'system') NOT NULL,
    content TEXT NOT NULL,
    metadata JSON NULL,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    FOREIGN KEY (conversation_id) REFERENCES chat_conversations(id) ON DELETE CASCADE
);
```

#### `flagged_chat_messages`
```sql
CREATE TABLE flagged_chat_messages (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    user_id BIGINT NOT NULL,
    conversation_id BIGINT NOT NULL,
    message_id BIGINT NULL,
    flag_type VARCHAR(255) NOT NULL,
    content TEXT NOT NULL,
    metadata JSON NULL,
    reviewed BOOLEAN DEFAULT FALSE,
    action_taken VARCHAR(255) NULL,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (conversation_id) REFERENCES chat_conversations(id) ON DELETE CASCADE,
    FOREIGN KEY (message_id) REFERENCES chat_messages(id) ON DELETE CASCADE,
    INDEX (user_id, flag_type),
    INDEX (reviewed)
);
```

## Backend Components

### 1. Models

#### ChatConversation Model
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChatConversation extends Model
{
    protected $fillable = [
        'user_id',
        'title',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'conversation_id');
    }
}
```

#### ChatMessage Model
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatMessage extends Model
{
    protected $fillable = [
        'conversation_id',
        'role',
        'content',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class, 'conversation_id');
    }
}
```

### 2. ChatService

The `ChatService` is the core service that handles message processing, context building, and LLM interaction:

```php
<?php

namespace App\Services;

use App\Models\User;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use Prism\Prism\Facades\Prism;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;

class ChatService
{
    public function __construct(
        protected ContentModerationService $moderationService
    ) {}

    /**
     * Process a message with streaming response
     */
    public function processMessageStream(
        User $user,
        string $message,
        ?int $conversationId = null,
        ?int $budgetId = null
    ) {
        // Implementation includes:
        // 1. Security checks (bans, rate limits)
        // 2. Content moderation
        // 3. Context building
        // 4. LLM interaction with streaming
        // 5. Response validation
    }

    /**
     * Build context for the LLM based on user data
     */
    public function buildContext(User $user, ?int $budgetId = null): array
    {
        // Customize this method to build context for different agent types
        // Current implementation focuses on financial data
    }

    /**
     * Build system prompt with agent instructions
     */
    protected function buildSystemPrompt(array $context): string
    {
        // This is where you customize agent behavior
        // Return different prompts based on agent type
    }
}
```

### 3. ChatController

The controller handles HTTP requests and delegates to the service:

```php
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

    /**
     * Send a message (non-streaming)
     */
    public function send(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'message' => 'required|string|max:2000',
            'conversation_id' => 'nullable|integer|exists:chat_conversations,id',
            'budget_id' => 'nullable|integer|exists:budgets,id',
        ]);

        return response()->json(
            $this->chatService->processMessage(
                user: $request->user(),
                message: $validated['message'],
                conversationId: $validated['conversation_id'] ?? null,
                budgetId: $validated['budget_id'] ?? null
            )
        );
    }

    /**
     * Stream a message response
     */
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

    // Additional methods for conversation management...
}
```

### 4. ContentModerationService

Handles security, content filtering, and rate limiting:

```php
<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;

class ContentModerationService
{
    /**
     * Check content for harmful patterns
     */
    public function checkContent(string $content, User $user): array
    {
        // Returns: ['safe' => bool, 'flags' => array, 'action' => string]
    }

    /**
     * Check rate limits
     */
    public function checkRateLimit(User $user): array
    {
        // Returns: ['allowed' => bool, 'remaining' => int, 'limit' => int]
    }

    /**
     * Build safe system prompt with guardrails
     */
    public function buildSafeSystemPrompt(string $basePrompt): string
    {
        // Adds safety instructions to prevent prompt injection
    }
}
```

## Frontend Components

### 1. ChatPanel Component

The main chat interface component:

```vue
<script setup>
import { ref, computed, watch, nextTick, onMounted } from 'vue';
import ChatMessage from './ChatMessage.vue';
import axios from 'axios';

const props = defineProps({
    isOpen: { type: Boolean, default: false }
});

const emit = defineEmits(['close']);

// Reactive state
const messages = ref([]);
const currentMessage = ref('');
const isLoading = ref(false);
const currentConversationId = ref(null);
const conversations = ref([]);

// Core functionality
const sendMessage = async () => {
    // Implements streaming message sending using EventSource
};

const loadConversation = async (conversationId) => {
    // Loads conversation history
};

// Additional methods for conversation management...
</script>

<template>
    <!-- Resizable panel with chat interface -->
</template>
```

### 2. ChatMessage Component

Renders individual messages with markdown support:

```vue
<script setup>
import { computed } from 'vue';
import { marked } from 'marked';
import DOMPurify from 'dompurify';

const props = defineProps({
    message: { type: Object, required: true }
});

const renderedContent = computed(() => {
    if (props.message.role === 'assistant') {
        const html = marked(props.message.content || '');
        return DOMPurify.sanitize(html);
    }
    return props.message.content;
});
</script>

<template>
    <!-- Message bubble with role-based styling -->
</template>
```

## Agent Configuration

### Creating Different Agent Types

To create different types of agents, you can extend the system in several ways:

#### 1. Agent Configuration Table (Recommended)

Create a new table to store agent configurations:

```sql
CREATE TABLE chat_agents (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    system_prompt TEXT NOT NULL,
    context_builder VARCHAR(255) NULL, -- Class name for custom context building
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
```

#### 2. Agent Model

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatAgent extends Model
{
    protected $fillable = [
        'name',
        'description', 
        'system_prompt',
        'context_builder',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
```

#### 3. Enhanced ChatService

Modify the `ChatService` to support different agents:

```php
public function processMessageStream(
    User $user,
    string $message,
    ?int $conversationId = null,
    ?int $budgetId = null,
    ?int $agentId = null
) {
    // Load agent configuration
    $agent = $agentId ? ChatAgent::findOrFail($agentId) : $this->getDefaultAgent();
    
    // Build context using agent-specific builder
    $context = $this->buildContextForAgent($user, $agent, $budgetId);
    
    // Build system prompt from agent configuration
    $systemPrompt = $this->buildSystemPromptForAgent($agent, $context);
    
    // Continue with existing logic...
}

protected function buildContextForAgent(User $user, ChatAgent $agent, ?int $budgetId): array
{
    if ($agent->context_builder) {
        $builderClass = $agent->context_builder;
        $builder = app($builderClass);
        return $builder->buildContext($user, $budgetId);
    }
    
    // Default context building
    return $this->buildContext($user, $budgetId);
}

protected function buildSystemPromptForAgent(ChatAgent $agent, array $context): string
{
    $prompt = $agent->system_prompt;
    
    // Replace placeholders with context data
    foreach ($context as $key => $value) {
        if (is_string($value) || is_numeric($value)) {
            $prompt = str_replace("{{$key}}", $value, $prompt);
        }
    }
    
    return $this->moderationService->buildSafeSystemPrompt($prompt);
}
```

#### 4. Context Builder Interface

Create an interface for custom context builders:

```php
<?php

namespace App\Contracts;

use App\Models\User;

interface ContextBuilderInterface
{
    public function buildContext(User $user, ?int $resourceId = null): array;
}
```

#### 5. Example Agent Implementations

**Financial Assistant Agent:**
```php
<?php

namespace App\Services\ContextBuilders;

use App\Contracts\ContextBuilderInterface;
use App\Models\User;

class FinancialContextBuilder implements ContextBuilderInterface
{
    public function buildContext(User $user, ?int $budgetId = null): array
    {
        // Build financial context (existing implementation)
        return [
            'user' => ['name' => $user->name],
            'budget' => $this->getBudgetData($user, $budgetId),
            'accounts' => $this->getAccountData($user, $budgetId),
            // ... other financial data
        ];
    }
}
```

**General Assistant Agent:**
```php
<?php

namespace App\Services\ContextBuilders;

use App\Contracts\ContextBuilderInterface;
use App\Models\User;

class GeneralContextBuilder implements ContextBuilderInterface
{
    public function buildContext(User $user, ?int $resourceId = null): array
    {
        return [
            'user' => [
                'name' => $user->name,
                'preferences' => $this->getUserPreferences($user),
            ],
            'current_time' => now()->format('Y-m-d H:i:s'),
            'application' => [
                'name' => config('app.name'),
                'features' => $this->getAvailableFeatures($user),
            ],
        ];
    }
}
```

## Security & Moderation

### Content Filtering

The system includes comprehensive content filtering:

- **Prompt injection detection** - Prevents attempts to override system instructions
- **Harmful content filtering** - Blocks malicious or inappropriate content
- **Rate limiting** - Prevents abuse with configurable limits
- **User banning** - Temporary restrictions for repeat offenders
- **Response validation** - Ensures AI responses don't leak sensitive information

### Rate Limiting Configuration

Routes are protected with Laravel's throttle middleware:

```php
Route::prefix('chat')->name('chat.')->middleware('throttle:60,1')->group(function () {
    Route::post('/message', [ChatController::class, 'send'])->middleware('throttle:30,1');
    Route::get('/stream', [ChatController::class, 'stream'])->middleware('throttle:30,1');
    // ...
});
```

### Security Best Practices

1. **Always validate and sanitize input**
2. **Use the moderation service for all user content**
3. **Implement proper CSRF protection**
4. **Log suspicious activities**
5. **Regularly review flagged content**
6. **Keep system prompts secure and not user-modifiable**

## Extending the System

### Adding New Agent Types

1. **Create agent configuration:**
   ```php
   ChatAgent::create([
       'name' => 'Customer Support Agent',
       'description' => 'Helps with customer support inquiries',
       'system_prompt' => 'You are a helpful customer support agent...',
       'context_builder' => CustomerSupportContextBuilder::class,
       'is_active' => true,
   ]);
   ```

2. **Implement context builder:**
   ```php
   class CustomerSupportContextBuilder implements ContextBuilderInterface
   {
       public function buildContext(User $user, ?int $resourceId = null): array
       {
           return [
               'user' => $this->getUserSupportContext($user),
               'tickets' => $this->getUserTickets($user),
               'knowledge_base' => $this->getRelevantArticles(),
           ];
       }
   }
   ```

3. **Update frontend to support agent selection:**
   ```vue
   <select v-model="selectedAgentId">
       <option v-for="agent in availableAgents" :key="agent.id" :value="agent.id">
           {{ agent.name }}
       </option>
   </select>
   ```

### Adding Custom Message Types

Extend the message role enum to support custom types:

```sql
ALTER TABLE chat_messages MODIFY COLUMN role ENUM('user', 'assistant', 'system', 'tool', 'function');
```

### Integrating External APIs

Add tool/function calling capabilities:

```php
public function processToolCall(string $toolName, array $parameters): array
{
    return match($toolName) {
        'get_weather' => $this->weatherService->getCurrentWeather($parameters['location']),
        'search_docs' => $this->documentService->search($parameters['query']),
        'create_ticket' => $this->ticketService->create($parameters),
        default => ['error' => 'Unknown tool'],
    };
}
```

## API Reference

### Endpoints

#### POST `/chat/message`
Send a message and receive a complete response.

**Request:**
```json
{
    "message": "What's my account balance?",
    "conversation_id": 123,
    "budget_id": 456,
    "agent_id": 1
}
```

**Response:**
```json
{
    "conversation_id": 123,
    "message": "Your current account balance is $1,234.56",
    "success": true
}
```

#### GET `/chat/stream`
Send a message and receive a streaming response via Server-Sent Events.

**Query Parameters:**
- `message` (required): The user's message
- `conversation_id` (optional): Existing conversation ID
- `budget_id` (optional): Budget context ID
- `agent_id` (optional): Agent configuration ID

**Response Events:**
- `conversation_id`: Conversation ID for new conversations
- `text_delta`: Incremental text chunks
- `stream_end`: Indicates completion
- `error`: Error information

#### GET `/chat/conversations`
List user's conversations.

**Response:**
```json
{
    "conversations": [
        {
            "id": 123,
            "title": "Budget Questions",
            "updated_at": "2 hours ago",
            "message_count": 5
        }
    ]
}
```

### Error Handling

The API returns consistent error responses:

```json
{
    "error": "Rate limit exceeded. Please try again later.",
    "success": false,
    "rate_limit": {
        "remaining": 0,
        "limit": 30,
        "reset_in_seconds": 3600
    }
}
```

## Best Practices

### Performance Optimization

1. **Use database indexing** for conversation and message queries
2. **Implement caching** for frequently accessed data
3. **Optimize context building** to avoid N+1 queries
4. **Use connection pooling** for LLM API calls
5. **Implement request queuing** for high-traffic scenarios

### User Experience

1. **Provide real-time feedback** with streaming responses
2. **Show typing indicators** during processing
3. **Implement message retry** for failed requests
4. **Support keyboard shortcuts** (Enter to send, etc.)
5. **Make the interface responsive** for mobile devices

### Monitoring & Analytics

1. **Log conversation metrics** (length, response time, user satisfaction)
2. **Monitor API usage** and costs
3. **Track moderation effectiveness**
4. **Analyze user engagement patterns**
5. **Set up alerts** for system issues

### Maintenance

1. **Regularly update system prompts** based on user feedback
2. **Review and improve moderation rules**
3. **Archive old conversations** to manage database size
4. **Update dependencies** and security patches
5. **Backup conversation data** regularly

## Conclusion

This chat agent system provides a solid foundation for building conversational AI features. The modular architecture allows for easy extension and customization while maintaining security and performance. By following this guide, you can implement various types of agents tailored to your specific use cases while leveraging the existing infrastructure for user management, security, and data persistence.

The system's agnostic design means you can easily adapt it for different domains beyond financial assistance, making it a versatile solution for any application requiring conversational AI capabilities.
