<?php
ignore_user_abort(true);
set_time_limit(120);

$lockFile = __DIR__ . '/cron.lock';
$lockHandle = fopen($lockFile, 'c');
if ($lockHandle === false) {
    die("LockError\n");
}
if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
    fclose($lockHandle);
    die("Locked\n");
}
register_shutdown_function(function () use ($lockHandle, $lockFile) {
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
    @unlink($lockFile);
});

$functionBootstrap = __DIR__ . '/function.php';
if (!is_readable($functionBootstrap)) {
    $functionBootstrap = __DIR__ . '/../function.php';
}

$bootstrapLoaded = false;
if (is_readable($functionBootstrap)) {
    try {
        require_once $functionBootstrap;
        $bootstrapLoaded = true;
    } catch (Throwable $e) {
    }
}

if (isset($conn) && $conn instanceof mysqli) {
    try { $conn->close(); } catch (Throwable $e) {}
} elseif (isset($mysqli) && $mysqli instanceof mysqli) {
    try { $mysqli->close(); } catch (Throwable $e) {}
} elseif (isset($db) && $db instanceof PDO) {
    $db = null;
}

if (function_exists('mysqli_close') && isset($GLOBALS['conn'])) {
    try { @mysqli_close($GLOBALS['conn']); } catch (Throwable $e) {}
}

$host = null;
if (isset($domainhosts) && is_string($domainhosts) && trim($domainhosts) !== '') {
    $host = $domainhosts;
}

if ($host === null || trim((string) $host) === '') {
    $host = $_SERVER['HTTP_HOST'] ?? null;
}

if ($host === null || trim((string) $host) === '') {
    $host = 'localhost';
}

$hostConfig = $host;
if (!preg_match('~^https?://~i', $hostConfig)) {
    $hostConfig = 'https://' . ltrim($hostConfig);
}

$parts    = parse_url($hostConfig);
$scheme   = $parts['scheme'] ?? 'https';
$hostOnly = $parts['host']   ?? 'localhost';
$basePath = rtrim($parts['path'] ?? '', '/');

$buildCronUrl = static function (string $script) use ($scheme, $hostOnly, $basePath): string {
    $script = ltrim($script, '/');
    $prefix = 'cronbot';
    $path = $basePath === '' ? '' : $basePath . '/';
    return $scheme . '://' . $hostOnly . $path . $prefix . '/' . $script;
};

function callEndpoint(string $url): void
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_FORBID_REUSE   => true,
    ]);
    
    curl_exec($ch);
    curl_close($ch);
    
    sleep(1);
}

$now       = time();
$minute    = (int) date('i', $now);
$hour      = (int) date('G', $now);
$dayOfYear = (int) date('z', $now);

if (!defined('APP_ROOT_PATH')) {
    define('APP_ROOT_PATH', dirname(__DIR__));
}

$stateDirectory = defined('APP_ROOT_PATH') ? APP_ROOT_PATH : __DIR__;
$runtimeStatePath = $stateDirectory . '/cron_runtime_state.json';
$runtimeState = [];

if (is_readable($runtimeStatePath)) {
    $stateContents = file_get_contents($runtimeStatePath);
    if ($stateContents !== false) {
        $decodedState = json_decode($stateContents, true);
        if (is_array($decodedState)) {
            $runtimeState = $decodedState;
        }
    }
}
$runtimeStateChanged = false;

$getIntervalSeconds = static function (array $schedule): int {
    $unit = isset($schedule['unit']) ? strtolower((string) $schedule['unit']) : 'minute';
    $value = isset($schedule['value']) ? (int) $schedule['value'] : 1;
    if ($value < 1) $value = 1;

    switch ($unit) {
        case 'minute': return 0;
        case 'hour':   return $value * 3600;
        case 'day':    return $value * 86400;
        default:       return 0;
    }
};

if ($bootstrapLoaded && function_exists('getCronJobDefinitions') && function_exists('shouldRunCronJob')) {
    $definitions = getCronJobDefinitions();
    $schedules = function_exists('loadCronSchedules') ? loadCronSchedules() : [];

    foreach ($definitions as $key => $definition) {
        if (empty($definition['script'])) continue;
        
        $defaultConfig = $definition['default'] ?? ['unit' => 'minute', 'value' => 1];
        $schedule = $schedules[$key] ?? $defaultConfig;
        
        if (!shouldRunCronJob($schedule, $minute, $hour, $dayOfYear)) continue;

        $intervalSeconds = $getIntervalSeconds($schedule);
        $lastRun = isset($runtimeState[$key]) ? (int) $runtimeState[$key] : 0;
        
        if ($intervalSeconds > 0 && ($now - $lastRun) < $intervalSeconds) continue;

        callEndpoint($buildCronUrl($definition['script']));
        
        if ($intervalSeconds > 0) {
            $runtimeState[$key] = $now;
            $runtimeStateChanged = true;
        }
    }
} else {
    $everyMinute = [
        'croncard.php', 'NoticationsService.php', 'sendmessage.php',
        'activeconfig.php', 'disableconfig.php', 'iranpay1.php',
    ];

    foreach ($everyMinute as $script) {
        callEndpoint($buildCronUrl($script));
    }

    if ($minute % 2 === 0) {
        foreach (['gift.php', 'configtest.php'] as $script) {
            callEndpoint($buildCronUrl($script));
        }
    }

    if ($minute % 3 === 0) {
        callEndpoint($buildCronUrl('plisio.php'));
    }

    if ($minute % 5 === 0) {
        callEndpoint($buildCronUrl('payment_expire.php'));
    }

    if ($minute % 15 === 0) {
        foreach (['statusday.php', 'on_hold.php', 'uptime_node.php', 'uptime_panel.php'] as $script) {
            callEndpoint($buildCronUrl($script));
        }
    }

    if ($minute % 30 === 0) {
        callEndpoint($buildCronUrl('expireagent.php'));
    }

    if ($minute === 0 && $hour % 5 === 0) {
        callEndpoint($buildCronUrl('backupbot.php'));
    }
}

if ($runtimeStateChanged) {
    file_put_contents(
        $runtimeStatePath,
        json_encode($runtimeState, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        LOCK_EX
    );
}

echo "OK\n";
