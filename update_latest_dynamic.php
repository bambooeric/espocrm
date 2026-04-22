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
    "SELECT id
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

foreach ($accountIds as $accountId) {
    $scanned++;

    $getLatestTaskStmt->execute([':accountId' => $accountId]);
    $latestTaskId = $getLatestTaskStmt->fetchColumn() ?: null;

    $getLatestAccountPostStmt->execute([':accountId' => $accountId]);
    $accountPost = $getLatestAccountPostStmt->fetch() ?: null;

    $taskPost = null;
    if ($latestTaskId) {
        $getLatestTaskPostStmt->execute([':taskId' => $latestTaskId]);
        $taskPost = $getLatestTaskPostStmt->fetch() ?: null;
    }

    $latestPostContent = null;

    if ($accountPost && $taskPost) {
        $accountTime = strtotime((string) $accountPost['updated_at']);
        $taskTime = strtotime((string) $taskPost['updated_at']);
        $latestPostContent = $accountTime >= $taskTime ? $accountPost['post'] : $taskPost['post'];
    } elseif ($accountPost) {
        $latestPostContent = $accountPost['post'];
    } elseif ($taskPost) {
        $latestPostContent = $taskPost['post'];
    }

    if ($latestPostContent !== null) {
        $updateAccountStmt->execute([
            ':value' => $latestPostContent,
            ':id' => $accountId,
        ]);

        if ($updateAccountStmt->rowCount() > 0) {
            $updatedAccountCount++;
        }
    }

    if ($latestTaskId && $taskPost && $taskPost['post'] !== null) {
        $updateTaskStmt->execute([
            ':value' => $taskPost['post'],
            ':id' => $latestTaskId,
        ]);

        if ($updateTaskStmt->rowCount() > 0) {
            $updatedTaskCount++;
        }
    }
}

printf(
    "Done. scanned=%d updated_accounts=%d updated_tasks=%d\n",
    $scanned,
    $updatedAccountCount,
    $updatedTaskCount
);
