<?php

declare(strict_types=1);

/**
 * 使用说明：
 * DB_HOST=127.0.0.1 DB_PORT=3306 DB_NAME=espocrm DB_USER=espocrm DB_PASS=secret php update_latest_dynamic.php
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run in CLI.\n");
    exit(1);
}

// 可在这里配置 Account / Task 保存“最新动态”内容的字段名。
$accountLastUpdateField = 'last_update';
$taskLastUpdateField = 'last_update';
// 是否强制更新全部（忽略“5分钟内才更新”的限制）。
$forceUpdateAll = false;
// 仅当记录最后修改时间在这个分钟数以内时才更新（force=true 时忽略）。
$updateWithinMinutes = 5;

if (!preg_match('/^[a-zA-Z0-9_]+$/', $accountLastUpdateField) || !preg_match('/^[a-zA-Z0-9_]+$/', $taskLastUpdateField)) {
    fwrite(STDERR, "Invalid field name.\n");
    exit(1);
}

$host = getenv('DB_HOST') ?: '127.0.0.1';
$port = getenv('DB_PORT') ?: '3306';
$dbName = getenv('DB_NAME') ?: '';
$user = getenv('DB_USER') ?: '';
$pass = getenv('DB_PASS') ?: '';
$charset = getenv('DB_CHARSET') ?: 'utf8mb4';

if ($dbName === '' || $user === '') {
    fwrite(STDERR, "Please provide DB_NAME and DB_USER.\n");
    exit(1);
}

$dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $host, $port, $dbName, $charset);

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    fwrite(STDERR, 'DB connection failed: ' . $e->getMessage() . "\n");
    exit(1);
}

$accountIds = $pdo->query('SELECT id FROM account WHERE deleted = 0')->fetchAll(PDO::FETCH_COLUMN);

$getLatestTaskStmt = $pdo->prepare(
    "SELECT id, name
    FROM task
    WHERE deleted = 0
      AND parent_type = 'Account'
      AND parent_id = :accountId
    ORDER BY COALESCE(modified_at, created_at) DESC
    LIMIT 1"
);

$getLatestAccountPostStmt = $pdo->prepare(
    "SELECT post, COALESCE(modified_at, created_at) AS updated_at
    FROM note
    WHERE deleted = 0
      AND parent_type = 'Account'
      AND parent_id = :accountId
      AND type = 'Post'
    ORDER BY COALESCE(modified_at, created_at) DESC
    LIMIT 1"
);

$getLatestTaskPostStmt = $pdo->prepare(
    "SELECT post, COALESCE(modified_at, created_at) AS updated_at
    FROM note
    WHERE deleted = 0
      AND parent_type = 'Task'
      AND parent_id = :taskId
      AND type = 'Post'
    ORDER BY COALESCE(modified_at, created_at) DESC
    LIMIT 1"
);

$updateAccountStmt = $pdo->prepare("UPDATE account SET {$accountLastUpdateField} = :value WHERE id = :id");
$updateTaskStmt = $pdo->prepare("UPDATE task SET {$taskLastUpdateField} = :value WHERE id = :id");

$scanned = 0;
$updatedAccountCount = 0;
$updatedTaskCount = 0;
$skippedByTimeCount = 0;
$freshThreshold = time() - ($updateWithinMinutes * 60);

foreach ($accountIds as $accountId) {
    $scanned++;

    $getLatestTaskStmt->execute([':accountId' => $accountId]);
    $latestTask = $getLatestTaskStmt->fetch() ?: null;
    $latestTaskId = $latestTask['id'] ?? null;
    $latestTaskName = $latestTask['name'] ?? '';

    $getLatestAccountPostStmt->execute([':accountId' => $accountId]);
    $accountPost = $getLatestAccountPostStmt->fetch() ?: null;

    $taskPost = null;
    if ($latestTaskId) {
        $getLatestTaskPostStmt->execute([':taskId' => $latestTaskId]);
        $taskPost = $getLatestTaskPostStmt->fetch() ?: null;
    }

    $latestPostContent = null;
    $latestPostTime = null;

    if ($accountPost && $taskPost) {
        $accountTime = strtotime((string) $accountPost['updated_at']);
        $taskTime = strtotime((string) $taskPost['updated_at']);
        if ($accountTime >= $taskTime) {
            $latestPostContent = $accountPost['post'];
            $latestPostTime = $accountTime;
        } else {
            $latestPostContent = sprintf('[%s]%s', $latestTaskName, (string) $taskPost['post']);
            $latestPostTime = $taskTime;
        }
    } elseif ($accountPost) {
        $latestPostContent = $accountPost['post'];
        $latestPostTime = strtotime((string) $accountPost['updated_at']);
    } elseif ($taskPost) {
        $latestPostContent = sprintf('[%s]%s', $latestTaskName, (string) $taskPost['post']);
        $latestPostTime = strtotime((string) $taskPost['updated_at']);
    }

    if (
        $latestPostContent !== null &&
        ($forceUpdateAll || ($latestPostTime !== null && $latestPostTime >= $freshThreshold))
    ) {
        $updateAccountStmt->execute([
            ':value' => $latestPostContent,
            ':id' => $accountId,
        ]);

        if ($updateAccountStmt->rowCount() > 0) {
            $updatedAccountCount++;
        }
    } elseif ($latestPostContent !== null) {
        $skippedByTimeCount++;
    }

    $taskPostTime = $taskPost ? strtotime((string) $taskPost['updated_at']) : null;

    if (
        $latestTaskId &&
        $taskPost &&
        $taskPost['post'] !== null &&
        ($forceUpdateAll || ($taskPostTime !== null && $taskPostTime >= $freshThreshold))
    ) {
        $updateTaskStmt->execute([
            ':value' => $taskPost['post'],
            ':id' => $latestTaskId,
        ]);

        if ($updateTaskStmt->rowCount() > 0) {
            $updatedTaskCount++;
        }
    } elseif ($latestTaskId && $taskPost && $taskPost['post'] !== null) {
        $skippedByTimeCount++;
    }
}

printf(
    "Done. scanned=%d updated_accounts=%d updated_tasks=%d skipped_by_time=%d force_update_all=%s\n",
    $scanned,
    $updatedAccountCount,
    $updatedTaskCount,
    $skippedByTimeCount,
    $forceUpdateAll ? 'true' : 'false'
);
