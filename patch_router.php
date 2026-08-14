<?php
$file = 'src/router.php';
$content = file_get_contents($file);

$old = <<<'OLD'
    foreach ($tasksMap as $data) {
        if (isset($data['deletedAt'])) continue;
        $status = isset($data['lastJobError']) ? 'failed' : 'queued';
OLD;

$new = <<<'NEW'
    foreach ($tasksMap as $data) {
        if (isset($data['deletedAt'])) {
            $status = isset($data['lastJobError']) ? 'failed' : 'completed';
        } else {
            $status = isset($data['lastJobError']) ? 'failed' : 'queued';
        }
NEW;

$content = str_replace($old, $new, $content);
file_put_contents($file, $content);
echo "Patched router.php\n";
