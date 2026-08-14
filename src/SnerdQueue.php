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
    private $pending_acks = [];
    public static $current_task_id = null;

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

    public function enqueue(string $task_id, string $task_type, array $data, int $max_retries = 3, float $retry_after_hours = 0.0, ?string $rate_limit_group = null, ?int $max_per_minute = null, ?bool $auto_dedupe = null, ?float $urgency_score = null): void
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
        if ($auto_dedupe !== null) {
            $payload['auto_dedupe'] = $auto_dedupe;
        }
        if ($urgency_score !== null) {
            $payload['urgency_score'] = $urgency_score;
        }

        $this->pending_acks[$task_id] = false;
        $this->sendMessage($payload);
        $this->waitForAck($task_id, 5);
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
                    } elseif ($msg['action'] === 'ack') {
                        if (isset($msg['task_id'])) {
                            $this->pending_acks[$msg['task_id']] = true;
                        }
                    } elseif ($msg['action'] === 'error') {
                        if (isset($msg['task_id'])) {
                            $this->pending_acks[$msg['task_id']] = new \RuntimeException($msg['message']);
                        } else {
                            echo "[Snerd] Error from engine: {$msg['message']}\n";
                        }
                    } elseif ($msg['action'] === 'progress') {
                        // In PHP, we just ignore incoming progress messages if they aren't meant for us.
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

        private function waitForAck(string $task_id, int $timeout_seconds = 5): void
    {
        $start = time();
        while (time() - $start < $timeout_seconds) {
            if (isset($this->pending_acks[$task_id])) {
                if ($this->pending_acks[$task_id] === true) {
                    unset($this->pending_acks[$task_id]);
                    return;
                }
                if ($this->pending_acks[$task_id] instanceof \Exception) {
                    $e = $this->pending_acks[$task_id];
                    unset($this->pending_acks[$task_id]);
                    throw $e;
                }
            }
            $this->tick(1);
        }
        throw new \RuntimeException("[Snerd] Timeout waiting for daemon Ack on task $task_id");
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
            self::$current_task_id = $task_id;
            call_user_func($this->handlers[$task_type], $task_data);
            self::$current_task_id = null;
            $this->sendMessage([
                'action' => 'result',
                'task_id' => $task_id,
                'status' => 'success'
            ]);
            self::$current_task_id = null;
        } catch (\Exception $e) {
            $this->sendMessage([
                'action' => 'result',
                'task_id' => $task_id,
                'status' => 'error',
                'error_msg' => $e->getMessage()
            ]);
        }
    }

    public function yieldProgress($data): void
    {
        if (self::$current_task_id === null) {
            throw new \RuntimeException("[Snerd] yieldProgress must be called within a task handler context.");
        }
        $this->sendMessage([
            'action' => 'progress',
            'task_id' => self::$current_task_id,
            'data' => is_string($data) ? $data : json_encode($data)
        ]);
    }

    public function startDashboard(int $port = 8080): void
    {
        $routerPath = __DIR__ . '/router.php';
        $staticPath = __DIR__ . '/../static';
        if (!file_exists($staticPath)) {
            $staticPath = __DIR__ . '/../../../../static';
        }
        $storage = $this->storage_path ?: './.snerdata';
        $cmd = "SNERD_STORAGE=" . escapeshellarg($storage) . " php -S 0.0.0.0:$port -t " . escapeshellarg($staticPath) . " " . escapeshellarg($routerPath);
        
        echo "[Snerd] Dashboard running on http://localhost:$port\n";
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            pclose(popen("start /B " . $cmd, "r"));
        } else {
            exec($cmd . " > /dev/null 2>&1 &");
        }
    }

}
