<?php

require_once __DIR__ . '/../src/SnerdQueue.php';

use Snerdmq\SnerdQueue;

echo "🚀 Booting up PHP SnerdMQ Test App...\n";

// Use the sibling rust repository binary directly for testing
$ext = (PHP_OS_FAMILY === 'Windows') ? '.exe' : '';
$bin_path = realpath(__DIR__ . "/../../snerdmq/target/debug/snerdmq{$ext}");
$db_path = realpath(__DIR__ . "/../../.snerdata/tasks/tasks.log");

if (file_exists($db_path)) {
    unlink($db_path); // Clean slate
}

$queue = new SnerdQueue($bin_path);
$job_finished = false;

$queue->registerHandler("test_php_job", function($data) use (&$job_finished) {
    if ($data['user_id'] !== 'php_master') {
        throw new \Exception("Assertion failed: user_id");
    }
    if ($data['message'] !== 'rasmus') {
        throw new \Exception("Assertion failed: message");
    }
    
    echo "\n✅ PHP App received job! Data: " . $data['message'] . "\n";
    $job_finished = true;
});

$queue->startListening();

// Give daemon a tiny fraction of a second to boot up
usleep(100000); // 0.1 seconds

echo "Enqueuing job to Rust daemon...\n";
$queue->enqueue(
    "php-job-1",
    "test_php_job",
    ["user_id" => "php_master", "message" => "rasmus"],
    3,
    0.0
);

// We manually run the listen loop with a timeout for tests
$start = microtime(true);
while (!$job_finished) {
    if (microtime(true) - $start > 5) {
        echo "❌ Timed out waiting for job\n";
        $queue->shutdown();
        exit(1);
    }
    
    // Process stdout buffers using non-blocking stream_select
    $queue->tick(); 
    usleep(10000); // Sleep for 10ms to prevent CPU pegging in the test loop
}

echo "🎉 Job processed successfully. Shutting down.\n";
$queue->shutdown();
exit(0);
