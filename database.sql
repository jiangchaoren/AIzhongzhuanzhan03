

CREATE TABLE `users` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(64) NOT NULL,
  `email` VARCHAR(190) DEFAULT NULL,
  `email_verified_at` DATETIME NULL,
  `email_verify_token` VARCHAR(64) NULL,
  `reset_token` VARCHAR(64) NULL,
  `reset_token_expires_at` DATETIME NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `role` ENUM('user', 'admin') NOT NULL DEFAULT 'user',
  `credits` INT NOT NULL DEFAULT 0,
  `watermark_points` INT NOT NULL DEFAULT 0,
  `invited_by` BIGINT UNSIGNED NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_users_username` (`username`),
  UNIQUE KEY `uniq_users_email` (`email`),
  KEY `idx_invited_by` (`invited_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `app_settings` (
  `setting_key` VARCHAR(100) NOT NULL,
  `setting_value` TEXT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `app_settings` (`setting_key`, `setting_value`) VALUES
  ('image_base_url', 'https://ai.umil.cn/v1'),
  ('image_api_key', ''),
  ('image_model', 'gpt-image-2'),
  ('cost_per_generation', '1'),
  ('balance_label', '余额'),
  ('draw_base_cost', '1'),
  ('edit_base_cost', '2'),
  ('max_edit_images', '4'),
  ('max_edit_image_mb', '10'),
  ('max_edit_image_dimension', '8000'),
  ('pay_pid', ''),
  ('pay_key', ''),
  ('pay_notify_url', ''),
  ('pay_return_url', ''),
  ('smtp_host', 'smtp.qq.com'),
  ('smtp_port', '465'),
  ('smtp_username', ''),
  ('smtp_password', ''),
  ('smtp_encryption', 'ssl'),
  ('smtp_from_email', ''),
  ('smtp_from_name', ''),
  ('captcha_id', ''),
  ('captcha_key', ''),
  ('captcha_enabled', 'off'),
  ('signup_bonus_enabled', 'off'),
  ('signup_bonus_credits', '10'),
  ('email_verify_enabled', 'on'),
  ('watermark_enabled', 'off'),
  ('watermark_text', 'AI Generated'),
  ('anti_watermark_enabled', 'off'),
  ('homepage_enabled', 'on'),
  ('generation_notice', '注意：因AI算力产图较慢，预计可能3-5分钟不止，请耐心等待，生成失败不消耗次数！')
ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`);

CREATE TABLE `generation_records` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `status` ENUM('queued', 'running', 'succeeded', 'failed') NOT NULL DEFAULT 'running',
  `mode` VARCHAR(16) NOT NULL DEFAULT 'draw',
  `model` VARCHAR(80) NOT NULL DEFAULT 'gpt-image-2',
  `ai_model_id` INT UNSIGNED NULL,
  `prompt` TEXT NOT NULL,
  `size` VARCHAR(32) NOT NULL DEFAULT 'auto',
  `resolution_level` ENUM('1K','2K','4K') NOT NULL DEFAULT '1K',
  `seconds` INT UNSIGNED NULL,
  `resolution_levels` VARCHAR(32) NOT NULL DEFAULT '1K',
  `quality` VARCHAR(32) NOT NULL DEFAULT 'auto',
  `output_format` VARCHAR(16) NOT NULL DEFAULT 'png',
  `input_images_json` LONGTEXT NULL,
  `image_base64` LONGTEXT NULL,
  `image_url` TEXT NULL,
  `mime_type` VARCHAR(64) NULL,
  `video_url` TEXT NULL,
  `video_base64` LONGTEXT NULL,
  `video_mime_type` VARCHAR(64) NULL,
  `video_task_id` VARCHAR(128) NULL,
  `video_task_status` VARCHAR(32) NULL,
  `video_task_response` LONGTEXT NULL,
  `credits_charged` INT NOT NULL DEFAULT 1,
  `anti_watermark` TINYINT(1) NOT NULL DEFAULT 0,
  `usage_json` JSON NULL,
  `error_message` TEXT NULL,
  `request_id` VARCHAR(128) NULL,
  `started_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `finished_at` DATETIME NULL,
  `deleted_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_generation_user_created` (`user_id`, `created_at`),
  KEY `idx_generation_status` (`status`),
  KEY `idx_generation_deleted_at` (`deleted_at`),
  CONSTRAINT `fk_generation_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `credit_codes` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(64) NOT NULL,
  `credits` INT NOT NULL,
  `max_uses` INT NOT NULL DEFAULT 1,
  `used_count` INT NOT NULL DEFAULT 0,
  `expires_at` DATETIME NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_by` BIGINT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_credit_codes_code` (`code`),
  KEY `idx_credit_codes_active` (`is_active`, `expires_at`),
  CONSTRAINT `fk_credit_codes_admin` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `shop_packages` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(64) NOT NULL,
  `description` TEXT NULL,
  `credits` INT NOT NULL,
  `price` DECIMAL(10,2) NOT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `flash_sale_enabled` TINYINT(1) NOT NULL DEFAULT 0,
  `flash_sale_start_time` DATETIME NULL,
  `flash_sale_end_time` DATETIME NULL,
  `flash_sale_price` DECIMAL(10,2) NULL,
  `flash_sale_stock` INT NOT NULL DEFAULT 0,
  `flash_sale_sold` INT NOT NULL DEFAULT 0,
  `group_buy_enabled` TINYINT(1) NOT NULL DEFAULT 0,
  `group_buy_min_count` INT NOT NULL DEFAULT 2,
  `watermark_points` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_packages_active` (`is_active`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `shop_packages` (`name`, `description`, `credits`, `price`, `sort_order`) VALUES
  ('体验套餐', '适合初次体验AI绘画功能', 10, 1.00, 1),
  ('入门套餐', '适合轻度使用，日常尝鲜', 50, 5.00, 2),
  ('标准套餐', '最受欢迎，适合日常创作', 200, 18.00, 3),
  ('进阶套餐', '适合频繁使用的创作者', 500, 40.00, 4),
  ('专业套餐', '适合重度使用，超值之选', 1200, 88.00, 5),
  ('旗舰套餐', '不限量创作，畅享AI绘画', 3000, 198.00, 6);

CREATE TABLE `orders` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `order_no` VARCHAR(64) NOT NULL,
  `trade_no` VARCHAR(64) NULL,
  `package_id` INT UNSIGNED NULL,
  `package_name` VARCHAR(64) NOT NULL,
  `credits` INT NOT NULL,
  `watermark_points` INT NOT NULL DEFAULT 0,
  `amount` DECIMAL(10,2) NOT NULL,
  `pay_type` VARCHAR(32) NULL,
  `status` ENUM('pending', 'paid', 'failed', 'refunded', 'expired') NOT NULL DEFAULT 'pending',
  `paid_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_orders_order_no` (`order_no`),
  KEY `idx_orders_user` (`user_id`, `created_at`),
  KEY `idx_orders_status` (`status`),
  KEY `idx_orders_trade_no` (`trade_no`),
  CONSTRAINT `fk_orders_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_orders_package` FOREIGN KEY (`package_id`) REFERENCES `shop_packages` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `credit_redemptions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `credits` INT NOT NULL,
  `redeemed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user_code` (`code_id`, `user_id`),
  KEY `idx_redemptions_user` (`user_id`, `redeemed_at`),
  CONSTRAINT `fk_redemptions_code` FOREIGN KEY (`code_id`) REFERENCES `credit_codes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_redemptions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `checkin_records` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `checkin_date` DATE NOT NULL,
  `consecutive_days` INT NOT NULL DEFAULT 1,
  `reward_credits` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user_date` (`user_id`, `checkin_date`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_checkin_date` (`checkin_date`),
  CONSTRAINT `fk_checkin_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `login_attempts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ip_hash` VARCHAR(64) NOT NULL,
  `username` VARCHAR(100) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ip_time` (`ip_hash`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 会员套餐系统 (2026-06-19)
-- ============================================================

CREATE TABLE `membership_packages` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(64) NOT NULL,
  `description` TEXT NULL,
  `duration_unit` ENUM('day','month','year') NOT NULL DEFAULT 'month',
  `duration_value` INT NOT NULL DEFAULT 1,
  `daily_quota` INT NOT NULL DEFAULT 0,
  `monthly_quota` INT NOT NULL DEFAULT 0,
  `yearly_quota` INT NOT NULL DEFAULT 0,
  `model_ids` TEXT NULL,
  `price` DECIMAL(10,2) NOT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_active_sort` (`is_active`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `membership_packages` (`name`, `description`, `duration_unit`, `duration_value`, `daily_quota`, `monthly_quota`, `yearly_quota`, `price`, `sort_order`) VALUES
  ('月度基础版', '新手首选，每日100点配额', 'month', 1, 100, 0, 0, 9.90, 1),
  ('月度专业版', '创作者之选，每日500点配额', 'month', 1, 500, 0, 0, 29.90, 2),
  ('季度旗舰版', '超值季度套餐，每日1000点配额', 'month', 3, 1000, 0, 0, 69.90, 3),
  ('年度至尊版', '年度会员，每月5000点配额', 'year', 1, 0, 5000, 0, 199.00, 4);

CREATE TABLE `user_memberships` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `package_id` INT UNSIGNED NOT NULL,
  `status` ENUM('active','expired','pending_upgrade') NOT NULL DEFAULT 'active',
  `daily_quota` INT NOT NULL DEFAULT 0,
  `monthly_quota` INT NOT NULL DEFAULT 0,
  `yearly_quota` INT NOT NULL DEFAULT 0,
  `model_quotas` TEXT NULL,
  `last_daily_reset` DATE NULL,
  `last_monthly_reset` DATE NULL,
  `last_yearly_reset` DATE NULL,
  `started_at` DATETIME NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `upgraded_to_package_id` INT UNSIGNED NULL,
  `upgrade_effective_at` DATETIME NULL,
  `upgrade_mode` ENUM('immediate','deferred') NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_status` (`user_id`, `status`),
  KEY `idx_status_expires` (`status`, `expires_at`),
  CONSTRAINT `fk_membership_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_membership_package` FOREIGN KEY (`package_id`) REFERENCES `membership_packages` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `membership_orders` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `order_no` VARCHAR(64) NOT NULL,
  `trade_no` VARCHAR(64) NULL,
  `package_id` INT UNSIGNED NULL,
  `package_name` VARCHAR(64) NOT NULL,
  `order_type` ENUM('new','renew','upgrade','topup') NOT NULL DEFAULT 'new',
  `credits` INT NOT NULL DEFAULT 0,
  `amount` DECIMAL(10,2) NOT NULL,
  `duration_unit` ENUM('day','month','year') NULL,
  `duration_value` INT NULL,
  `daily_quota` INT NULL,
  `monthly_quota` INT NULL,
  `yearly_quota` INT NULL,
  `upgrade_mode` ENUM('immediate','deferred') NULL,
  `pay_type` VARCHAR(32) NULL,
  `status` ENUM('pending','paid','failed','refunded') NOT NULL DEFAULT 'pending',
  `paid_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_order_no` (`order_no`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_status` (`status`),
  CONSTRAINT `fk_morder_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 余额变动日志
-- ============================================================

CREATE TABLE `balance_reset_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `membership_id` BIGINT UNSIGNED NULL,
  `reset_type` ENUM('daily','monthly','yearly','expire_clear','manual','topup') NOT NULL,
  `credits_before` INT NOT NULL DEFAULT 0,
  `credits_added` INT NOT NULL DEFAULT 0,
  `credits_after` INT NOT NULL DEFAULT 0,
  `reset_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_reset` (`user_id`, `reset_at`),
  KEY `idx_type` (`reset_type`),
  CONSTRAINT `fk_reset_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- AI 模型配置表
-- ============================================================

CREATE TABLE `ai_models` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(64) NOT NULL,
  `model_id` VARCHAR(80) NOT NULL,
  `base_url` VARCHAR(255) NOT NULL,
  `api_path` VARCHAR(255) NOT NULL DEFAULT '',
  `edit_api_path` VARCHAR(255) NOT NULL DEFAULT '',
  `timeout` INT UNSIGNED NOT NULL DEFAULT 0,
  `download_timeout` INT UNSIGNED NOT NULL DEFAULT 0,
  `site_type` VARCHAR(32) NOT NULL DEFAULT 'standard',
  `agnes_config` TEXT NULL,
  `grok_config` TEXT NULL,
  `api_key` VARCHAR(255) NOT NULL DEFAULT '',
  `model_type` VARCHAR(16) NOT NULL DEFAULT 'image',
  `credits` INT UNSIGNED DEFAULT NULL,
  `resolution_level` ENUM('1K','2K','4K') NOT NULL DEFAULT '1K',
  `resolution_levels` VARCHAR(32) NOT NULL DEFAULT '1K',
  `credits_1k` INT UNSIGNED DEFAULT NULL,
  `credits_2k` INT UNSIGNED DEFAULT NULL,
  `credits_4k` INT UNSIGNED DEFAULT NULL,
  `watermark_point_cost` INT UNSIGNED NOT NULL DEFAULT 0,
  `sort_order` INT NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_models_active` (`is_active`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- API 令牌管理
-- ============================================================

CREATE TABLE `api_tokens` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL,
  `name` VARCHAR(100) NOT NULL DEFAULT '',
  `token_hash` VARCHAR(64) NOT NULL,
  `permissions` TEXT,
  `last_used_at` DATETIME DEFAULT NULL,
  `expires_at` DATETIME DEFAULT NULL,
  `is_revoked` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `idx_token_hash` (`token_hash`),
  KEY `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- AI 对话系统
-- ============================================================

CREATE TABLE `chat_conversations` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL,
  `title` VARCHAR(200) NOT NULL DEFAULT '新对话',
  `model_id` INT UNSIGNED DEFAULT NULL,
  `message_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_user_id` (`user_id`),
  KEY `idx_updated_at` (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `chat_records` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL,
  `conversation_id` INT UNSIGNED DEFAULT NULL,
  `prompt` TEXT NOT NULL,
  `reply` TEXT NOT NULL,
  `tokens` INT UNSIGNED NOT NULL DEFAULT 0,
  `model` VARCHAR(100) NOT NULL DEFAULT '',
  `credits_cost` INT UNSIGNED NOT NULL DEFAULT 0,
  `user_ip` VARCHAR(45) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_user_id` (`user_id`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_conversation_id` (`conversation_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 图片广场
-- ============================================================

CREATE TABLE `gallery` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `record_id` INT NOT NULL,
  `username` VARCHAR(100) NOT NULL DEFAULT '',
  `prompt` TEXT,
  `image_url` VARCHAR(2000) NOT NULL DEFAULT '',
  `mime_type` VARCHAR(50) NOT NULL DEFAULT 'image/png',
  `model` VARCHAR(100) NOT NULL DEFAULT '',
  `mode` VARCHAR(20) NOT NULL DEFAULT 'draw',
  `size` VARCHAR(20) NOT NULL DEFAULT 'auto',
  `likes` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_user_id` (`user_id`),
  KEY `idx_created` (`created_at`),
  KEY `idx_mode` (`mode`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 拼团系统
-- ============================================================

CREATE TABLE `group_buy_sessions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `package_id` INT UNSIGNED NOT NULL,
  `initiator_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `target_count` INT NOT NULL DEFAULT 2,
  `current_count` INT NOT NULL DEFAULT 1,
  `status` ENUM('open','completed','expired','cancelled') NOT NULL DEFAULT 'open',
  `expires_at` DATETIME NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_package_id` (`package_id`),
  KEY `idx_status_expires` (`status`, `expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 第三方社交登录
-- ============================================================

CREATE TABLE `social_logins` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `type` VARCHAR(20) NOT NULL COMMENT '登录方式',
  `social_uid` VARCHAR(100) NOT NULL,
  `access_token` VARCHAR(200) DEFAULT NULL,
  `nickname` VARCHAR(100) DEFAULT NULL,
  `faceimg` VARCHAR(500) DEFAULT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `idx_type_social_uid` (`type`, `social_uid`),
  KEY `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 邀请分佣
-- ============================================================

CREATE TABLE `invite_commissions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `inviter_id` INT NOT NULL,
  `invited_user_id` INT NOT NULL,
  `order_id` INT NULL,
  `amount` DECIMAL(10,2) NOT NULL DEFAULT 0,
  `credits` INT NOT NULL DEFAULT 0,
  `type` VARCHAR(20) NOT NULL DEFAULT 'recharge',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_inviter` (`inviter_id`),
  KEY `idx_invited` (`invited_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 余额/水印点变动日志
-- ============================================================

CREATE TABLE `credit_records` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL,
  `type` ENUM('credit_add','credit_deduct','wp_add','wp_deduct','admin_set') NOT NULL,
  `amount` INT NOT NULL DEFAULT 0,
  `balance_after` INT NOT NULL DEFAULT 0,
  `description` VARCHAR(255) NOT NULL DEFAULT '',
  `operator_id` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_user_created` (`user_id`, `created_at`),
  KEY `idx_type` (`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 文件存储映射表
-- ============================================================

CREATE TABLE `storage_files` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `storage_key` VARCHAR(512) NOT NULL,
  `mime_type` VARCHAR(128) NOT NULL DEFAULT 'application/octet-stream',
  `file_size` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `data` LONGBLOB NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_storage_key` (`storage_key`(191)),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
