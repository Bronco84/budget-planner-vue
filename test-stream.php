<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

try {
    echo "Creating Prism request...\n";
    $pending = \Prism\Prism\Facades\Prism::text()
        ->using('openai', 'gpt-4o-mini')
        ->withPrompt('Count to 5');
    
    echo "Calling asStream()...\n";
    $stream = $pending->asStream();
    
    echo "Stream object type: " . get_class($stream) . "\n";
    echo "Is Generator: " . ($stream instanceof Generator ? 'yes' : 'no') . "\n";
    echo "Starting iteration...\n";
    
    $count = 0;
    $text = '';
    
    foreach ($stream as $event) {
        $count++;
        echo "Event #$count: " . get_class($event) . "\n";
        
        if ($event instanceof \Prism\Prism\Streaming\Events\TextDeltaEvent) {
            echo "  Delta: {$event->delta}\n";
            $text .= $event->delta;
        }
        
        if ($count > 50) {
            echo "Stopping after 50 events...\n";
            break;
        }
    }
    
    echo "\nTotal events: $count\n";
    echo "Full text: $text\n";
} catch (\Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
}

