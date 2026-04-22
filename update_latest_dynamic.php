<?php

declare(strict_types=1);

/**
 * Update account/task latest post snapshot into last_update fields.
 *
 * Run example:
 *   DB_HOST=127.0.0.1 DB_PORT=3306 DB_NAME=espocrm DB_USER=espocrm DB_PASS=secret \
 *   php update_latest_dynamic.php
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from CLI.\n");
    exit(1);
}

$timezone = getenv('TZ') ?: 'UTC';
date_default_timezone_set($timezone);

$host = getenv('DB_HOST') ?: '127.0.0.1';
$port = getenv('DB_PORT') ?: '3306';
$dbName = getenv('DB_NAME') ?: '';
$user = getenv('DB_USER') ?: '';
$pass = getenv('DB_PASS') ?: '';
$charset = getenv('DB_CHARSET') ?: 'utf8mb4';

if ($dbName === '' || $user === '') {
    fwrite(STDERR, "Missing DB_NAME or DB_USER environment variable.\n");
    exit(1);
}

$dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $host, $port, $dbName, $charset);

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $e) {
    fwrite(STDERR, 'DB connection failed: ' . $e->getMessage() . "\n");
    exit(1);
}

$requiredColumns = [
    'account' => ['id', 'deleted', 'last_update'],
    'task' => ['id', 'deleted', 'parent_id', 'parent_type', 'last_update', 'created_at', 'modified_at'],
    'note' => ['id', 'deleted', 'parent_id', 'parent_type', 'type', 'post', 'created_at', 'modified_at'],
];

$columnCheckStmt = $pdo->prepare(
    'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = :schema AND table_name = :tableName AND column_name = :columnName'
);

foreach ($requiredColumns as $tableName => $columns) {
    foreach ($columns as $columnName) {
        $columnCheckStmt->execute([
            ':schema' => $dbName,
            ':tableName' => $tableName,
            ':columnName' => $columnName,
        ]);

        if ((int) $columnCheckStmt->fetchColumn() === 0) {
            fwrite(STDERR, sprintf("Missing required column `%s`.`%s`.\n", $tableName, $columnName));
            exit(1);
        }
    }
}

$accountStmt = $pdo->query('SELECT id FROM account WHERE deleted = 0');

$latestTaskStmt = $pdo->prepare(
    "SELECT id
    FROM task
    WHERE deleted = 0
      AND parent_type = 'Account'
      AND parent_id = :accountId
    ORDER BY COALESCE(modified_at, created_at) DESC
    LIMIT 1"
);

$latestAccountPostStmt = $pdo->prepare(
    "SELECT id, post, COALESCE(modified_at, created_at) AS updated_at
    FROM note
    WHERE deleted = 0
      AND parent_type = 'Account'
      AND parent_id = :accountId
      AND type = 'Post'
    ORDER BY COALESCE(modified_at, created_at) DESC
    LIMIT 1"
);

$latestTaskPostStmt = $pdo->prepare(
    "SELECT id, post, COALESCE(modified_at, created_at) AS updated_at
    FROM note
    WHERE deleted = 0
      AND parent_type = 'Task'
      AND parent_id = :taskId
      AND type = 'Post'
    ORDER BY COALESCE(modified_at, created_at) DESC
    LIMIT 1"
);

$updateAccountStmt = $pdo->prepare('UPDATE account SET last_update = :lastUpdate WHERE id = :accountId');
$updateTaskStmt = $pdo->prepare('UPDATE task SET last_update = :lastUpdate WHERE id = :taskId');

$totalAccounts = 0;
$updatedAccounts = 0;
$updatedTasks = 0;

$pdo->beginTransaction();

try {
    while ($account = $accountStmt->fetch()) {
        $totalAccounts++;
        $accountId = $account['id'];

        $latestTaskStmt->execute([':accountId' => $accountId]);
        $taskId = $latestTaskStmt->fetchColumn() ?: null;

        $latestAccountPostStmt->execute([':accountId' => $accountId]);
        $accountPost = $latestAccountPostStmt->fetch() ?: null;

        $taskPost = null;

        if ($taskId) {
            $latestTaskPostStmt->execute([':taskId' => $taskId]);
            $taskPost = $latestTaskPostStmt->fetch() ?: null;
        }

        $latestPost = null;

        if ($accountPost && $taskPost) {
            $latestPost = strtotime((string) $accountPost['updated_at']) >= strtotime((string) $taskPost['updated_at'])
                ? $accountPost
                : $taskPost;
        } else {
            $latestPost = $accountPost ?: $taskPost;
        }

        if ($latestPost && array_key_exists('post', $latestPost)) {
            $updateAccountStmt->execute([
                ':lastUpdate' => $latestPost['post'],
                ':accountId' => $accountId,
            ]);
            $updatedAccounts += $updateAccountStmt->rowCount() > 0 ? 1 : 0;
        }

        if ($taskId && $taskPost && array_key_exists('post', $taskPost)) {
            $updateTaskStmt->execute([
                ':lastUpdate' => $taskPost['post'],
                ':taskId' => $taskId,
            ]);
            $updatedTasks += $updateTaskStmt->rowCount() > 0 ? 1 : 0;
        }
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    fwrite(STDERR, 'Failed to update latest dynamic: ' . $e->getMessage() . "\n");
    exit(1);
}

printf(
    "Done. scanned_accounts=%d updated_accounts=%d updated_tasks=%d\n",
    $totalAccounts,
    $updatedAccounts,
    $updatedTasks
);
