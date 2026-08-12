<div align="center">
  <h1>🐘 SnerdMQ PHP SDK</h1>
  <p>A zero-config, C-speed background job queue for modern PHP. Ditch Redis and heavy queue workers for a simple, embedded Rust daemon.</p>

  [![Packagist Version](https://img.shields.io/packagist/v/greyhands2/snerdmq)](https://packagist.org/packages/greyhands2/snerdmq)
</div>

This is the official PHP SDK wrapper for **SnerdMQ**. It handles all JSON-RPC communication and `proc_open` orchestration so you can write lightning-fast background jobs in Laravel, Symfony, or vanilla PHP without managing any external databases like Redis, Beanstalkd, or RabbitMQ.

## ✨ Features
- **Ditch Redis**: Gives your PHP apps persistent state, automatic retries, and dead-letter queues right out of the box with zero external infrastructure.
- **Zero Rust Required**: Our Composer installation script automatically downloads the pre-compiled C-speed Rust binary for your OS.
- **Non-Blocking**: Uses native PHP `stream_select` to listen to the daemon's output efficiently without pegging your CPU or requiring heavy C-extensions like Swoole.

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

// 4. Enqueue a job from anywhere in your codebase
$queue->enqueue(
    "email-123",
    "send_email",
    ["to" => "rasmus@php.net", "subject" => "SnerdMQ Update"],
    3,    // max retries
    0.0   // retry after hours
);

// 5. Run the event loop (usually done in a dedicated worker script)
$queue->listenLoop();
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
