-- Guest Wi-Fi OTP portal - schema
--
-- Easiest path: browse to install.php, which creates all of this and can
-- also upgrade an existing install. Import this file through phpMyAdmin
-- only if you prefer doing it by hand.

CREATE DATABASE IF NOT EXISTS guestwifi
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE guestwifi;

-- Optional whitelist. Only consulted when REQUIRE_ALLOWED_LIST is true.
CREATE TABLE IF NOT EXISTS allowed_mobiles (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    mobile     VARCHAR(20) NOT NULL UNIQUE,
    label      VARCHAR(100) DEFAULT NULL,
    enabled    TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One row per OTP. `device_id` is the device the code was issued to, and
-- is what makes the OTP unusable anywhere else. `used` is the single-use
-- latch: it is set with a conditional UPDATE, so two simultaneous
-- verifications can never both succeed.
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
    -- pending | verified | expired | superseded | locked | send_failed
    status      VARCHAR(16) NOT NULL DEFAULT 'pending',
    expires_at  DATETIME NOT NULL,
    verified_at DATETIME DEFAULT NULL,
    created_at  DATETIME NOT NULL,
    KEY idx_mobile_used (mobile, used),
    KEY idx_device (device_id),
    KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- An open internet session: this device, for this number, until expires_at.
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
    -- expired | reconnect | replaced | user_logout | admin
    end_reason  VARCHAR(32) DEFAULT NULL,
    UNIQUE KEY uq_token (token_hash),
    KEY idx_mobile_active (mobile, active),
    KEY idx_device_active (device_id, active),
    KEY idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Audit trail of every allow/reject decision.
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- What the SMS gateway was asked to do and what it answered.
-- The OTP itself is masked before the message is stored.
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
