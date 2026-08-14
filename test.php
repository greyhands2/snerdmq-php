<?php
require 'vendor/autoload.php';
use Snerdmq\SnerdQueue;

$queue = new SnerdQueue(null, __DIR__ . '/.snerdata');

$queue->registerHandler('mock_task', function($data) use ($queue) {
    $queue->yieldProgress("Starting task...");
    sleep(1);
    $queue->yieldProgress("Halfway done...");
    sleep(1);
    $queue->yieldProgress("Finished.");
});

echo "Starting dashboard on 8080...\n";
$queue->startDashboard(8080);

echo "Starting listener...\n";
$queue->startListening();

// Spawn a background enqueuer
if (pcntl_fork() == 0) {
    for ($i = 0; $i < 20; $i++) {
        $queue->enqueue("test_" . time() . "_$i", "mock_task", ["foo" => "bar"]);
        sleep(2);
    }
    exit(0);
}

echo "Listening for tasks (300s)...\n";
for ($i = 0; $i < 300; $i++) {
    $queue->tick(1);
}

$queue->shutdown();
echo "Done.\n";
