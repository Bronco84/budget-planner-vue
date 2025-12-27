<?php

use Illuminate\Support\Facades\Route;
use Prism\Prism\Facades\Prism;

Route::get('/debug/prism-methods', function () {
    $pending = Prism::text()->using('openai', 'gpt-4o-mini');
    
    $methods = get_class_methods($pending);
    
    return response()->json([
        'class' => get_class($pending),
        'has_asStream' => method_exists($pending, 'asStream'),
        'has_stream' => method_exists($pending, 'stream'),
        'has_asEventStreamResponse' => method_exists($pending, 'asEventStreamResponse'),
        'all_methods' => $methods,
    ]);
});

Route::get('/debug/test-streaming', function () {
    try {
        $pending = Prism::text()
            ->using('openai', 'gpt-4o-mini')
            ->withPrompt('Say hello in 5 words');
        
        // Try to get the stream
        $stream = $pending->asStream();
        
        $chunks = [];
        $count = 0;
        $fullText = '';
        
        foreach ($stream as $event) {
            $count++;
            
            $eventData = [
                'type' => get_class($event),
                'event_type_enum' => method_exists($event, 'type') ? $event->type()->value : null,
            ];
            
            if ($event instanceof \Prism\Prism\Streaming\Events\TextDeltaEvent) {
                $eventData['delta'] = $event->delta;
                $fullText .= $event->delta;
            }
            
            if ($event instanceof \Prism\Prism\Streaming\Events\TextStartEvent) {
                $eventData['note'] = 'Text generation started';
            }
            
            if ($event instanceof \Prism\Prism\Streaming\Events\TextCompleteEvent) {
                $eventData['note'] = 'Text generation complete';
                $eventData['text'] = property_exists($event, 'text') ? $event->text : 'N/A';
            }
            
            $chunks[] = $eventData;
            
            if ($count > 20) break; // Get more events
        }
        
        return response()->json([
            'success' => true,
            'chunk_count' => $count,
            'full_text' => $fullText,
            'sample_chunks' => $chunks,
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
    }
});

