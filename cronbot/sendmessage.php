<?php
$isCLI = (php_sapi_name() === 'cli');
$isCron = ($isCLI || !isset($_SERVER['HTTP_HOST']));
@ini_set('output_buffering', 'off');
@ini_set('implicit_flush', true);
@set_time_limit(30);
@ini_set('memory_limit', '128M');
$baseDir = dirname(__FILE__);
date_default_timezone_set('Asia/Tehran');
require_once $baseDir . '/../config.php';
require_once $baseDir . '/../botapi.php';
require_once $baseDir . '/../function.php';
$lockFile = $baseDir . '/broadcast.lock';
if (file_exists($lockFile) && (time() - filemtime($lockFile)) < 300) {
    exit;
}
file_put_contents($lockFile, getmypid() . '|' . date('Y-m-d H:i:s'));
try {
    $cacheFile = $baseDir . '/textbot_cache.json';
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 3600) {
        $datatextbot = json_decode(file_get_contents($cacheFile), true);
    } else {
        $datatextbotget = select("textbot", "*", null, null, "fetchAll");
        $datatxtbot = array();
        foreach ($datatextbotget as $row) {
            $datatxtbot[] = array(
                'id_text' => $row['id_text'],
                'text' => $row['text']
            );
        }
        $datatextbot = array(
            'text_usertest' => '',
            'text_support' => '',
            'text_help' => '',
            'text_sell' => '',
            'text_affiliates' => '',
            'text_Add_Balance' => ''
        );
        foreach ($datatxtbot as $item) {
            if (isset($datatextbot[$item['id_text']])) {
                $datatextbot[$item['id_text']] = $item['text'];
            }
        }
        file_put_contents($cacheFile, json_encode($datatextbot, JSON_UNESCAPED_UNICODE));
    }
    $infoFile = $baseDir . '/info';
    $usersFile = $baseDir . '/users.json';
    if (!is_file($infoFile)) {
        @unlink($lockFile);
        exit;
    }
    if (!is_file($usersFile)) {
        @unlink($lockFile);
        exit;
    }
    $useridContent = file_get_contents($usersFile);
    $infoContent = file_get_contents($infoFile);
    if ($useridContent === false || $infoContent === false) {
        @unlink($lockFile);
        exit;
    }
    $userid = json_decode($useridContent);
    $info = json_decode($infoContent, true);
    if (!is_array($userid) || !is_array($info)) {
        @unlink($lockFile);
        exit;
    }

    if (count($userid) == 0) {
        if (isset($info['id_admin']) && isset($info['id_message'])) {
            deletemessage($info['id_admin'], $info['id_message']);

            sendmessage($info['id_admin'], "✅ عملیات ارسال پیام با موفقیت به پایان رسید.\n\n📊 گزارش نهایی عملیات تکمیل شد.", null, 'HTML');
        }
        @unlink($infoFile);
        @unlink($usersFile);
        @unlink($cacheFile);
        @unlink($usersFile . '.tmp');
        @unlink($lockFile);
        exit;
    }

    $keyboards = [
        'none' => null,
        'buy' => json_encode(['inline_keyboard' => [[['text' => $datatextbot['text_sell'], 'callback_data' => 'buy']]]]),
        'start' => json_encode(['inline_keyboard' => [[['text' => "شروع", 'callback_data' => 'start']]]]),
        'usertestbtn' => json_encode(['inline_keyboard' => [[['text' => $datatextbot['text_usertest'], 'callback_data' => 'usertestbtn']]]]),
        'helpbtn' => json_encode(['inline_keyboard' => [[['text' => $datatextbot['text_help'], 'callback_data' => 'helpbtn']]]]),
        'affiliatesbtn' => json_encode(['inline_keyboard' => [[['text' => $datatextbot['text_affiliates'], 'callback_data' => 'affiliatesbtn']]]]),
        'addbalance' => json_encode(['inline_keyboard' => [[['text' => $datatextbot['text_Add_Balance'], 'callback_data' => 'Add_Balance']]]]),
    ];
    $cancelmessage = json_encode(['inline_keyboard' => [[['text' => "لغو عملیات", 'callback_data' => 'cancel_sendmessage']]]]);
    $batchSize = 150;
    $softTimeLimit = 28;
    $batchStartTime = microtime(true);
    $processed = 0;
    $success = 0;
    $blocked = 0;
    $deleted = 0;
    $failed = 0;
    $chatNotFound = 0;
    $deleteStmt = $pdo->prepare("DELETE FROM user WHERE id = :id");
    while (!empty($userid) && $processed < $batchSize) {
        $elapsed = microtime(true) - $batchStartTime;
        if ($elapsed >= $softTimeLimit) {
            break;
        }
        $iduser = array_shift($userid);
        if (!isset($iduser->id) || !is_numeric($iduser->id)) {
            continue;
        }
        $processed++;
        $userId = $iduser->id;
        if ($info['type'] == "unpinmessage") {
            unpinmessage($userId);
        } elseif ($info['type'] == "sendmessage" || $info['type'] == "xdaynotmessage") {
            $keyboard = $keyboards[$info['btnmessage']] ?? null;
            $meesage = sendmessage($userId, $info['message'], $keyboard, 'HTML');
            if (isset($meesage['ok']) && !$meesage['ok']) {
                $errorDesc = $meesage['description'] ?? 'unknown error';
                if ($errorDesc == "Forbidden: bot was blocked by the user") {
                    $blocked++;
                    $checkStmt = $pdo->prepare("SELECT
                        (SELECT COUNT(*) FROM invoice WHERE id_user = :id) as invoice_count,
                        Balance
                        FROM user WHERE id = :id2 LIMIT 1");
                    $checkStmt->execute([':id' => $userId, ':id2' => $userId]);
                    $result = $checkStmt->fetch(PDO::FETCH_ASSOC);
                    if ($result && $result['invoice_count'] == 0 && $result['Balance'] == 0) {
                        $deleteStmt->execute([':id' => $userId]);
                        $deleted++;
                    }
                } elseif (strpos($errorDesc, 'chat not found') !== false) {
                    $chatNotFound++;
                    $deleteStmt->execute([':id' => $userId]);
                    $deleted++;
                } else {
                    $failed++;
                }
            } elseif (isset($meesage['ok']) && $meesage['ok']) {
                $success++;
                if (isset($info['pingmessage']) && $info['pingmessage'] == "yes" &&
                    isset($meesage['result']['message_id'])) {
                    pinmessage($userId, $meesage['result']['message_id']);
                }
            }
        } elseif ($info['type'] == "forwardmessage") {
            $meesage = forwardMessage($info['id_admin'], $info['message'], $userId);
            if (isset($meesage['ok']) && !$meesage['ok']) {
                $errorDesc = $meesage['description'] ?? 'unknown error';
                if ($errorDesc == "Forbidden: bot was blocked by the user") {
                    $blocked++;
                } elseif (strpos($errorDesc, 'chat not found') !== false) {
                    $chatNotFound++;
                    $deleteStmt->execute([':id' => $userId]);
                    $deleted++;
                } else {
                    $failed++;
                }
            } elseif (isset($meesage['ok']) && $meesage['ok']) {
                $success++;
                if (isset($info['pingmessage']) && $info['pingmessage'] == "yes" &&
                    isset($meesage['result']['message_id'])) {
                    pinmessage($userId, $meesage['result']['message_id']);
                }
            }
        }
        if ($processed % 25 == 0) {
            usleep(50000);
        }
    }
    $batchExecutionTime = microtime(true) - $batchStartTime;
    $messagesPerSecond = $processed > 0 ? $processed / $batchExecutionTime : 0;
    $count_remain = count($userid);

    if ($count_remain == 0) {
        if (isset($info['id_admin']) && isset($info['id_message'])) {
            deletemessage($info['id_admin'], $info['id_message']);

            $textfinish = "✅ عملیات ارسال پیام با موفقیت به پایان رسید.\n\n";
            $textfinish .= "📊 گزارش نهایی:\n";
            $textfinish .= "✅ موفق: " . number_format($success) . " پیام\n";
            if ($blocked > 0) $textfinish .= "🚫 بلاک شده: " . number_format($blocked) . "\n";
            if ($chatNotFound > 0) $textfinish .= "📵 Chat ناموجود: " . number_format($chatNotFound) . "\n";
            if ($deleted > 0) $textfinish .= "🗑 حذف شده: " . number_format($deleted) . "\n";
            if ($failed > 0) $textfinish .= "❌ خطا: " . number_format($failed) . "\n";

            sendmessage($info['id_admin'], $textfinish, null, 'HTML');
        }

        @unlink($infoFile);
        @unlink($usersFile);
        @unlink($cacheFile);
        @unlink($usersFile . '.tmp');
        @unlink($lockFile);
        exit;
    }

    $textprocces = "✏️ عملیات ارسال پیام درحال انجام...\n\n";
    $textprocces .= "📊 باقی‌مانده: " . number_format($count_remain) . " نفر\n";
    $textprocces .= "🚀 ارسال شده: " . number_format($processed) . " پیام\n";
    $textprocces .= "✅ موفق: " . number_format($success);
    if ($blocked > 0) $textprocces .= " | 🚫 بلاک: " . number_format($blocked);
    if ($chatNotFound > 0) $textprocces .= " | 📵 Chat ناموجود: " . number_format($chatNotFound);
    if ($deleted > 0) $textprocces .= " | 🗑 حذف: " . number_format($deleted);
    if ($failed > 0) $textprocces .= " | ❌ خطا: " . number_format($failed);
    $textprocces .= "\n\n⏱ زمان: " . round($batchExecutionTime, 1) . "s";
    $textprocces .= " | 🔥 سرعت: " . round($messagesPerSecond, 1) . " پیام/ثانیه";
    if (isset($info['id_admin']) && isset($info['id_message'])) {
        Editmessagetext($info['id_admin'], $info['id_message'], $textprocces, $cancelmessage);
    }

    if ($count_remain > 0) {
        $tempFile = $usersFile . '.tmp';
        file_put_contents($tempFile, json_encode($userid, JSON_UNESCAPED_UNICODE));
        if (file_exists($usersFile)) {
            @unlink($usersFile);
        }
        rename($tempFile, $usersFile);
    }
} catch (Exception $e) {

} finally {
    @unlink($lockFile);
}
?>
