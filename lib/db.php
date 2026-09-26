<?php
/**
 * Database connection plus an idempotent schema installer.
 * install.php and the CLI runner both call gw_install_schema(); it is safe
 * to run repeatedly and will add columns missing from an older install.
 */

require_once __DIR__ . '/util.php';

function db(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }

    return $pdo;
}

/** Connection to the server itself, used to create the database. */
function gw_server_pdo(): PDO
{
    return new PDO('mysql:host=' . DB_HOST . ';charset=utf8mb4', DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
}

function gw_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?'
    );
    $stmt->execute([DB_NAME, $table]);
    return (int) $stmt->fetchColumn() > 0;
}

function gw_column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute([DB_NAME, $table, $column]);
    return (int) $stmt->fetchColumn() > 0;
}

/**
 * Creates the database and tables if they are absent, and tops up any
 * column added after the first release.
 *
 * @return string[] human-readable list of what changed
 */
function gw_install_schema(): array
{
    $done = [];

    $server = gw_server_pdo();
    $server->exec(
        'CREATE DATABASE IF NOT EXISTS `' . DB_NAME . '`
         CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
    );
    $done[] = 'database `' . DB_NAME . '` ready';

    $pdo = db();

    $tables = [
        'allowed_mobiles' => "
            CREATE TABLE IF NOT EXISTS allowed_mobiles (
                id         INT AUTO_INCREMENT PRIMARY KEY,
                mobile     VARCHAR(20) NOT NULL UNIQUE,
                label      VARCHAR(100) DEFAULT NULL,
                enabled    TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'otp_requests' => "
            CREATE TABLE IF NOT EXISTS otp_requests (
                id          BIGINT AUTO_INCREMENT PRIMARY KEY,
                mobile      VARCHAR(20) NOT NULL,
                otp_hash    VARCHAR(255) NOT NULL,
                device_id   VARCHAR(128) NOT NULL,
                mac_address VARCHAR(32) DEFAULT NULL,
                ip_address  VARCHAR(45) DEFAULT NULL,
                user_agent  VARCHAR(255) DEFAULT NULL,
                attempts    TINYINT UNSIGNED NOT NULL DEFAULT 0,
                used        TINYINT(1) NOT NULL DEFAULT 0,
                status      VARCHAR(16) NOT NULL DEFAULT 'pending',
                expires_at  DATETIME NOT NULL,
                verified_at DATETIME DEFAULT NULL,
                created_at  DATETIME NOT NULL,
                KEY idx_mobile_used (mobile, used),
                KEY idx_device (device_id),
                KEY idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'guest_sessions' => "
            CREATE TABLE IF NOT EXISTS guest_sessions (
                id          BIGINT AUTO_INCREMENT PRIMARY KEY,
                mobile      VARCHAR(20) NOT NULL,
                device_id   VARCHAR(128) NOT NULL,
                mac_address VARCHAR(32) DEFAULT NULL,
                ip_address  VARCHAR(45) DEFAULT NULL,
                user_agent  VARCHAR(255) DEFAULT NULL,
                otp_id      BIGINT DEFAULT NULL,
                token_hash  CHAR(64) NOT NULL,
                active      TINYINT(1) NOT NULL DEFAULT 1,
                started_at  DATETIME NOT NULL,
                expires_at  DATETIME NOT NULL,
                ended_at    DATETIME DEFAULT NULL,
                end_reason  VARCHAR(32) DEFAULT NULL,
                UNIQUE KEY uq_token (token_hash),
                KEY idx_mobile_active (mobile, active),
                KEY idx_device_active (device_id, active),
                KEY idx_expires (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'login_history' => "
            CREATE TABLE IF NOT EXISTS login_history (
                id         BIGINT AUTO_INCREMENT PRIMARY KEY,
                mobile     VARCHAR(20) DEFAULT NULL,
                device_id  VARCHAR(128) DEFAULT NULL,
                ip_address VARCHAR(45) DEFAULT NULL,
                success    TINYINT(1) NOT NULL DEFAULT 0,
                reason     VARCHAR(48) DEFAULT NULL,
                created_at DATETIME NOT NULL,
                KEY idx_mobile_success (mobile, success, created_at),
                KEY idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'sms_log' => "
            CREATE TABLE IF NOT EXISTS sms_log (
                id         BIGINT AUTO_INCREMENT PRIMARY KEY,
                mobile     VARCHAR(20) NOT NULL,
                driver     VARCHAR(20) NOT NULL,
                message    VARCHAR(500) DEFAULT NULL,
                ok         TINYINT(1) NOT NULL DEFAULT 0,
                http_code  INT DEFAULT NULL,
                response   TEXT,
                created_at DATETIME NOT NULL,
                KEY idx_mobile (mobile, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];

    foreach ($tables as $name => $sql) {
        $existed = gw_table_exists($pdo, $name);
        $pdo->exec($sql);
        $done[] = $existed ? "table `$name` present" : "table `$name` created";
    }

    // Top-up for installs made before a column existed.
    $additions = [
        ['otp_requests',   'device_id',   "VARCHAR(128) NOT NULL DEFAULT ''"],
        ['otp_requests',   'mac_address', 'VARCHAR(32) DEFAULT NULL'],
        ['otp_requests',   'attempts',    'TINYINT UNSIGNED NOT NULL DEFAULT 0'],
        ['otp_requests',   'status',      "VARCHAR(16) NOT NULL DEFAULT 'pending'"],
        ['otp_requests',   'user_agent',  'VARCHAR(255) DEFAULT NULL'],
        ['login_history',  'reason',      'VARCHAR(48) DEFAULT NULL'],
        ['allowed_mobiles', 'label',      'VARCHAR(100) DEFAULT NULL'],
    ];

    foreach ($additions as [$table, $column, $definition]) {
        if (gw_table_exists($pdo, $table) && !gw_column_exists($pdo, $table, $column)) {
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
            $done[] = "column `$table`.`$column` added";
        }
    }

    return $done;
}

/**
 * Closes sessions and OTPs whose time has run out. Cheap enough to call on
 * every API hit, which keeps the portal correct without a cron job.
 */
function gw_expire_stale(): void
{
    $now = gw_ts();

    $stmt = db()->prepare(
        "UPDATE guest_sessions
            SET active = 0, ended_at = ?, end_reason = 'expired'
          WHERE active = 1 AND expires_at <= ?"
    );
    $stmt->execute([$now, $now]);

    $stmt = db()->prepare(
        "UPDATE otp_requests
            SET used = 1, status = 'expired'
          WHERE used = 0 AND expires_at <= ?"
    );
    $stmt->execute([$now]);
}
