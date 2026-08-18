<?php

$repo = "speed-nerd/snerdmq";
$version = "v0.1.1";

$os = PHP_OS_FAMILY;
$cpu = php_uname('m');

$platform = match ($os) {
    'Darwin' => 'macos',
    'Linux' => 'linux',
    'Windows' => 'windows',
    default => null
};

if (!$platform) {
    echo "[Snerd] Unsupported OS: $os\n";
    exit(1);
}

$architecture = match (true) {
    (bool)preg_match('/x86_64|amd64/i', $cpu) => 'x64',
    (bool)preg_match('/arm64|aarch64/i', $cpu) => 'arm64',
    default => null
};

if (!$architecture) {
    echo "[Snerd] Unsupported Architecture: $cpu\n";
    exit(1);
}

$ext = $platform === 'windows' ? '.exe' : '';
$binary_name = "snerdmq-{$platform}-{$architecture}{$ext}";
$download_url = "https://github.com/{$repo}/releases/download/{$version}/{$binary_name}";

// In a Composer environment, we want to place it in the same `bin` directory 
// so the SnerdQueue orchestrator knows exactly where to find it.
$dest_dir = __DIR__;
$dest_path = "{$dest_dir}/snerdmq{$ext}";

echo "[Snerd] Downloading pre-compiled engine from GitHub: {$binary_name}...\n";

// PHP file_get_contents follows redirects by default!
$context = stream_context_create([
    'http' => [
        'user_agent' => 'SnerdMQ-PHP-Installer',
        'follow_location' => 1
    ]
]);

$binary_data = @file_get_contents($download_url, false, $context);

if ($binary_data === false) {
    echo "\n[Snerd] WARN: Binary not found at $download_url\n";
    echo "[Snerd] (This is expected if you haven't published a GitHub Release yet)\n";
    echo "[Snerd] Please provide binary_path manually when initializing SnerdQueue.\n";
    exit(0);
}

file_put_contents($dest_path, $binary_data);

if ($platform !== 'windows') {
    chmod($dest_path, 0755);
}

echo "[Snerd] Successfully installed Snerd Engine to {$dest_path}!\n";
