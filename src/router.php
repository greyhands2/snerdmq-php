<?php
// Simple router for PHP built-in server to serve dashboard APIs
header('Access-Control-Allow-Origin: *');

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$storage = getenv('SNERD_STORAGE') ?: './.snerdata';
$tasksPath = $storage . '/tasks/tasks.log';
$progressPath = $storage . '/progress_events.log';

if ($uri === '/api/progress') {
    $events = [];
    if (file_exists($progressPath)) {
        $lines = file($progressPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach (array_slice($lines, -100) as $line) {
            $ev = json_decode($line, true);
            if ($ev && isset($ev['ts'])) {
                $events[] = $ev;
            }
        }
    }
    header('Content-Type: application/json');
    echo json_encode($events);
    exit;
} elseif ($uri === '/api/stats') {
    $enqueued = 0; $processed = 0; $failed = 0;
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
    foreach ($tasksMap as $data) {
        $enqueued++;
        if (isset($data['deletedAt'])) {
            if (isset($data['LastJobError'])) {
                $failed++;
            } else {
                $processed++;
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
            if (isset($data['LastJobError']) && ($data['retryCount'] ?? 0) >= ($data['maxRetries'] ?? 3)) {
                $status = 'dead_letter';
            } elseif (isset($data['LastJobError'])) {
                $status = 'failed';
            } else {
                $status = 'completed';
            }
        } elseif (isset($data['LastJobError'])) {
            $status = 'failed';
        } else {
            $status = 'queued';
            if (!empty($data['executeAt'])) {
                $execTime = strtotime($data['executeAt']);
                if ($execTime !== false && $execTime <= time()) {
                    $status = 'active';
                }
            }
        }
        $res[] = [
            'id' => $data['taskId'],
            'type' => $data['taskType'],
            'status' => $status,
            'progress' => 0,
            'retryCount' => $data['retryCount'] ?? 0,
            'maxRetries' => $data['maxRetries'] ?? 3,
            'retryAfterTime' => $data['retryAfterTime'] ?? null,
            'cronExpression' => $data['cronExpression'] ?? null,
            'webhookUrl' => $data['webhookUrl'] ?? null,
            'maxExecutionSeconds' => $data['maxExecutionSeconds'] ?? null
        ];
    }
    header('Content-Type: application/json');
    echo json_encode($res);
    exit;
}

// Serve static files if not matched
return false;
