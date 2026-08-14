<?php

namespace Snerdmq;

class SnerdQueue
{
    private $binary_path;
    private $storage_path;
    private $handlers = [];
    
    private $process;
    private $pipes;
    private $is_shutting_down = false;

    public function __construct(?string $binary_path = null, ?string $storage_path = null)
    {
        $this->binary_path = $binary_path;
        $this->storage_path = $storage_path;

        if ($this->binary_path === null) {
            $ext = (PHP_OS_FAMILY === 'Windows') ? '.exe' : '';
            $this->binary_path = realpath(__DIR__ . "/../bin/snerdmq{$ext}");
        }

        if (!file_exists($this->binary_path)) {
            throw new \RuntimeException("[Snerd] Binary not found at {$this->binary_path}. Ensure it is compiled or run 'php bin/snerdmq-install.php'.");
        }
    }

    public function registerHandler(string $task_type, callable $callback): void
    {
        $this->handlers[$task_type] = $callback;
        
        if (is_resource($this->process) && !$this->is_shutting_down) {
            $this->sendMessage([
                'action' => 'register',
                'task_type' => $task_type
            ]);
        }
    }

    public function startListening(): void
    {
        $cmd = escapeshellarg($this->binary_path);
        if ($this->storage_path) {
            $cmd .= " " . escapeshellarg($this->storage_path);
        }

        $descriptorspec = [
            0 => ["pipe", "r"],  // stdin
            1 => ["pipe", "w"],  // stdout
            2 => ["pipe", "w"]   // stderr
        ];

        $this->process = proc_open($cmd, $descriptorspec, $this->pipes);

        if (!is_resource($this->process)) {
            throw new \RuntimeException("Failed to spawn SnerdMQ process.");
        }

        // Make stdout non-blocking for stream_select
        stream_set_blocking($this->pipes[1], false);

        // Register existing handlers
        foreach (array_keys($this->handlers) as $task_type) {
            $this->sendMessage([
                'action' => 'register',
                'task_type' => $task_type
            ]);
        }
    }

    public function enqueue(string $task_id, string $task_type, array $data, int $max_retries = 3, float $retry_after_hours = 0.0, ?string $rate_limit_group = null, ?int $max_per_minute = null): void
    {
        if (!is_resource($this->process) || $this->is_shutting_down) {
            throw new \RuntimeException("[Snerd] Cannot enqueue task: Queue is not running. Call startListening first.");
        }

        $payload = [
            'action' => 'enqueue',
            'task_id' => $task_id,
            'task_type' => $task_type,
            'task_data' => json_encode($data),
            'max_retries' => $max_retries,
            'retry_after_hours' => $retry_after_hours
        ];

        if ($rate_limit_group !== null) {
            $payload['rate_limit_group'] = $rate_limit_group;
        }
        if ($max_per_minute !== null) {
            $payload['max_per_minute'] = $max_per_minute;
        }

        $this->sendMessage($payload);
    }

    public function tick(int $timeout_seconds = 1): void
    {
        if (!is_resource($this->process) || $this->is_shutting_down) {
            return;
        }

        $read = [$this->pipes[1]];
        $write = null;
        $except = null;

        // Block until there is data on stdout, or timeout is reached
        $num_changed_streams = stream_select($read, $write, $except, $timeout_seconds, 0);

        if ($num_changed_streams === false) {
            return; // interrupted or error
        }

        if ($num_changed_streams > 0) {
            while ($line = fgets($this->pipes[1])) {
                $line = trim($line);
                if (empty($line)) continue;
                
                $msg = json_decode($line, true);
                if ($msg && isset($msg['action'])) {
                    if ($msg['action'] === 'execute') {
                        $this->handleExecute($msg);
                    } elseif ($msg['action'] === 'max_retries_reached') {
                        echo "[Snerd] Dead Letter Queue: Task {$msg['task_id']} ({$msg['task_type']}) permanently failed.\n";
                    }
                }
            }
        }
    }

    public function listenLoop(): void
    {
        while (!$this->is_shutting_down && is_resource($this->process)) {
            $this->tick();
        }
    }

    public function shutdown(): void
    {
        $this->is_shutting_down = true;

        if (is_resource($this->process)) {
            $status = proc_get_status($this->process);
            if ($status['running']) {
                proc_terminate($this->process, 15); // SIGTERM
            }
            
            fclose($this->pipes[0]);
            fclose($this->pipes[1]);
            fclose($this->pipes[2]);
            
            proc_close($this->process);
        }
    }

    private function sendMessage(array $msg): void
    {
        if ($this->is_shutting_down || !is_resource($this->process)) return;

        $json = json_encode($msg) . "\n";
        fwrite($this->pipes[0], $json);
        fflush($this->pipes[0]);
    }

    private function handleExecute(array $msg): void
    {
        $task_id = $msg['task_id'];
        $task_type = $msg['task_type'];
        
        $raw_data = $msg['task_data'];
        $task_data = is_string($raw_data) ? json_decode($raw_data, true) : $raw_data;

        if (!isset($this->handlers[$task_type])) {
            $this->sendMessage([
                'action' => 'result',
                'task_id' => $task_id,
                'status' => 'error',
                'error_msg' => 'No handler registered.'
            ]);
            return;
        }

        try {
            call_user_func($this->handlers[$task_type], $task_data);
            $this->sendMessage([
                'action' => 'result',
                'task_id' => $task_id,
                'status' => 'success'
            ]);
        } catch (\Exception $e) {
            $this->sendMessage([
                'action' => 'result',
                'task_id' => $task_id,
                'status' => 'error',
                'error_msg' => $e->getMessage()
            ]);
        }
    }
}
