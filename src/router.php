<?php
// Simple router for PHP built-in server to serve dashboard APIs
header('Access-Control-Allow-Origin: *');

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$storage = getenv('SNERD_STORAGE') ?: './.snerdata';
$tasksPath = $storage . '/tasks/tasks.log';

if ($uri === '/api/stats') {
    $enqueued = 0; $processed = 0; $failed = 0;
    if (file_exists($tasksPath)) {
        $lines = file($tasksPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $enqueued++;
            if (strpos($line, '"deletedAt":"') !== false) {
                if (strpos($line, '"lastJobError":"') !== false) {
                    $failed++;
                } else {
                    $processed++;
                }
            }
        }
    }
    header('Content-Type: application/json');
    echo json_encode(['enqueued' => $enqueued, 'processed' => $processed, 'failed' => $failed]);
    exit;
} elseif ($uri === '/api/tasks') {
    $tasksMap = [];
    if (file_exists($tasksPath)) {
        $lines = file($tasksPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $data = json_decode($line, true);
            if ($data && isset($data['taskId'])) {
                $tasksMap[$data['taskId']] = $data;
            }
        }
    }
    $res = [];
    foreach ($tasksMap as $data) {
        if (isset($data['deletedAt'])) {
            $status = isset($data['lastJobError']) ? 'failed' : 'completed';
        } else {
            $status = isset($data['lastJobError']) ? 'failed' : 'queued';
        }
        $res[] = [
            'id' => $data['taskId'],
            'type' => $data['taskType'],
            'status' => $status,
            'progress' => 0,
            'retryCount' => $data['retryCount'] ?? 0,
            'maxRetries' => $data['maxRetries'] ?? 3,
            'retryAfterTime' => $data['retryAfterTime'] ?? null
        ];
    }
    header('Content-Type: application/json');
    echo json_encode($res);
    exit;
}

// Serve static files if not matched
return false;
