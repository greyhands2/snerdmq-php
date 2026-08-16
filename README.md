<div align="center">
  <img src="./assets/Designer-9.png" height="120" alt="SnerdMQ PHP Logo" />
  <h1>🐘 SnerdMQ PHP SDK v0.3.0</h1>
  <p>A zero-config, C-speed background job queue for modern PHP. Ditch Redis and heavy queue workers for a simple, embedded Rust daemon.</p>

  [![Packagist Version](https://img.shields.io/packagist/v/greyhands2/snerdmq)](https://packagist.org/packages/greyhands2/snerdmq)
</div>

This is the official PHP SDK wrapper for **SnerdMQ**. It handles all JSON-RPC communication and `proc_open` orchestration so you can write lightning-fast background jobs in Laravel, Symfony, or vanilla PHP without managing any external databases like Redis, Beanstalkd, or RabbitMQ.

## ✨ v0.3.0 AI Features
- **Smart API Rate-Limiting**: Natively tracks `rate_limit_group` execution velocity to prevent 429 "Too Many Requests" API errors.
- **Payload-Hashing Deduplication**: Automatically computes cryptographic hashes to drop duplicate tasks instantly.
- **Dynamic Float Prioritization**: A native Binary Max-Heap bypasses standard FIFO rules for high urgency tasks.
- **Ditch Redis**: Gives your PHP apps persistent state, automatic retries, and dead-letter queues right out of the box with zero external infrastructure.
- **Zero Rust Required**: Our Composer installation script automatically downloads the pre-compiled C-speed Rust binary for your OS.
- **Non-Blocking**: Uses native PHP `stream_select` to listen to the daemon's output efficiently without pegging your CPU or requiring heavy C-extensions like Swoole.

### ⚙️ Advanced Task Configuration (v0.3.0)
To power complex AI workflows, tasks can now be configured with advanced orchestration parameters:

* **`auto_dedupe` (`bool`)**: If set to `true`, the daemon computes a cryptographic hash of the `task_type` and `data`. If an identical payload is currently sitting in the queue pending execution, this new task is silently dropped. Excellent for preventing duplicate generative AI requests from trigger-happy users!
* **`urgency_score` (`float`)**: A value (e.g. `0.99`) used to bypass the standard FIFO queue. SnerdMQ uses a true Binary Max-Heap to continually float tasks with the highest urgency score to the very front of the execution line. Standard tasks default to `0.0`.
* **`rate_limit_group` (`string`)**: A custom string (e.g. `"openai_api"` or `"db_writes"`) that groups tasks together for backpressure control.
* **`max_per_minute` (`int`)**: Used in conjunction with `rate_limit_group`. If the queue processes more tasks in this group than the allowed limit within a 60-second rolling window, further tasks in this group are temporarily paused. This natively prevents 429 "Too Many Requests" errors when bursting third-party APIs.
* **`execute_at` (`string` | `DateTimeInterface`)**: A timestamp of when the job should be executed in the future.
* **`cron` (`string`)**: A cron expression (e.g. `"0 * * * *"`) for recurring jobs. Shorthands like `"2h"` or `"10m"` are also supported.
* **`webhookUrl` (`string`)**: By providing a webhook URL, SnerdMQ will completely bypass your local PHP closures and dispatch the task payload via an HTTP POST request directly to the specified URL.

### 🌐 HTTP Webhooks (Serverless Execution)
You can configure a task to execute externally via an HTTP POST request. By setting a `webhookUrl`, the internal background processor will skip any registered handlers (`queue->registerHandler`) and directly invoke the HTTP endpoint.

If the HTTP endpoint returns a non-200 status code, it triggers a retry. If it permanently fails (reaches `maxRetries`), the Dead Letter Queue event is automatically fired via a final HTTP POST to the same `webhookUrl` but with the header `X-SnerdMQ-Event: MaxRetriesReached`.

### 🕒 Cron Jobs vs. Retryable Jobs
When using the new scheduling features, it is important to understand the difference between Cron and Retry behaviors:
> - **A Cron Job** is a *Repeatable Job* that executes again **only after a success**, on a fixed schedule.
> - **A Retryable Job** is a *Recovery Job* that executes again **only after a failure**, attempting to recover using the `retry_after_hours` backoff.
> - **Combined:** If a Cron Job fails, it temporarily uses `retry_after_hours` to retry until it recovers. Once it succeeds, it goes back to ticking on its standard cron schedule!

## 📦 Installation

Installing the SDK is a simple one-step process via Composer:

```bash
composer require greyhands2/snerdmq
```

*Note: The moment you run this command, Composer will automatically execute our post-install hook to download the correct highly-optimized SnerdMQ binary for your operating system (macOS/Linux/Windows) directly into the `bin/` directory!*

---

## ⚡ Quickstart

Using the SDK is incredibly simple. Initialize the queue, register your handler closures, and start listening!

```php
<?php

require 'vendor/autoload.php';
use Snerdmq\SnerdQueue;

// 1. Initialize the daemon in the background
$queue = new SnerdQueue();

// 2. Register your background job logic using a standard closure
$queue->registerHandler("send_email", function($data) {
    $to = $data["to"];
    $subject = $data["subject"];
    echo "Sending email to {$to} with subject: {$subject}...\n";
    
    // Throw an Exception here to automatically trigger SnerdMQ's retry logic!
});

// 3. Start the process
$queue->startListening();
echo "SnerdMQ PHP SDK is listening for jobs...\n";

// 4. Enqueue a job from anywhere in your codebase (Now with v0.2.1 AI Features!)
$queue->enqueue(
    "email-123",
    "send_email",
    ["to" => "rasmus@php.net", "subject" => "SnerdMQ Update"],
    3,              // max retries
    0.0,            // retry after hours
    "email_api",    // rate_limit_group
    100,            // max_per_minute
    true,           // auto_dedupe
    0.99,           // urgency_score
    null,           // execute_at
    "1h",           // cron: Runs every 1 hour!
    "https://api.example.com/webhook" // webhookUrl: Execute via HTTP instead of local handlers
);

// 5. Run the event loop (usually done in a dedicated worker script)
$queue->listenLoop();
```

### ☠️ Dead Letter Queue (Handling Permanent Failures)

When a task fails repeatedly and exhausts its `maxRetries`, the SnerdMQ daemon permanently moves it to the Dead Letter Queue. You can hook into this event to alert your team, update your database, or send a Slack message by registering a Max Retry Handler.

```php
// 5. Catch tasks that have permanently failed (Dead Letter Queue)
$queue->registerMaxRetryHandler('send_email', function($data) {
    echo "Email task failed after all retries! Data: " . json_encode($data) . "\n";
});
```

---

## 🌍 Advanced: Distributed Scaling

By default, the SDK spins up the Rust daemon which writes the queue to a local file (`.snerdata/tasks/tasks.log`). 

If you have multiple PHP microservices running behind a load balancer and want them to share the exact same queue, simply mount a **Shared Network Drive** (like AWS EFS or NFS) to all of your servers and pass the shared path:

```php
// All of your PHP servers point to the exact same shared file!
// SnerdMQ's native OS file-locking guarantees zero data corruption.
$queue = new SnerdQueue(null, "/mnt/aws-efs-shared-drive/snerd_tasks.log");
```

*Built with ❤️ for John Wick tier engineering.*
