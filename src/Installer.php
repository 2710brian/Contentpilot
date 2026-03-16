<?php

namespace AEBG;

/**
 * Installer Class
 *
 * @package AEBG
 */
class Installer {
	/**
	 * Activate the plugin.
	 */
	public function activate() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		$sql_batches = "CREATE TABLE `{$wpdb->prefix}aebg_batches` (
			`id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			`user_id` BIGINT(20) UNSIGNED NOT NULL,
			`template_id` BIGINT(20) UNSIGNED NOT NULL,
			`settings` JSON NOT NULL,
			`status` VARCHAR(20) NOT NULL DEFAULT 'pending',
			`total_items` INT UNSIGNED NOT NULL DEFAULT 0,
			`processed_items` INT UNSIGNED NOT NULL DEFAULT 0,
			`failed_items` INT UNSIGNED NOT NULL DEFAULT 0,
			`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			`updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (`id`),
			KEY `idx_status` (`status`),
			KEY `idx_user_id` (`user_id`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
		dbDelta( $sql_batches );

		$sql_items = "CREATE TABLE `{$wpdb->prefix}aebg_batch_items` (
			`id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			`batch_id` BIGINT(20) UNSIGNED NOT NULL,
			`generated_post_id` BIGINT(20) UNSIGNED NULL,
			`source_title` TEXT NOT NULL,
			`status` VARCHAR(20) NOT NULL DEFAULT 'pending',
			`log_message` TEXT NULL,
			`checkpoint_state` LONGTEXT NULL COMMENT 'JSON-encoded checkpoint state for resume',
			`checkpoint_step` VARCHAR(50) NULL COMMENT 'Current step identifier for resume',
			`resume_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Number of resume attempts',
			`last_checkpoint_at` DATETIME NULL COMMENT 'Timestamp of last checkpoint save',
			`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			`updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (`id`),
			KEY `idx_batch_id` (`batch_id`),
			KEY `idx_status` (`status`),
			KEY `idx_checkpoint_step` (`checkpoint_step`),
			KEY `idx_resume_count` (`resume_count`),
			UNIQUE KEY `idx_batch_title_unique` (`batch_id`, `source_title`(255))
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
		dbDelta( $sql_items );

		// Add checkpoint columns to existing table if they don't exist (for upgrades)
		$items_table = $wpdb->prefix . 'aebg_batch_items';
		$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$items_table}" );
		
		if ( ! in_array( 'checkpoint_state', $columns, true ) ) {
			$wpdb->query( "ALTER TABLE {$items_table} ADD COLUMN `checkpoint_state` LONGTEXT NULL COMMENT 'JSON-encoded checkpoint state for resume'" );
		}
		if ( ! in_array( 'checkpoint_step', $columns, true ) ) {
			$wpdb->query( "ALTER TABLE {$items_table} ADD COLUMN `checkpoint_step` VARCHAR(50) NULL COMMENT 'Current step identifier for resume'" );
		}
		if ( ! in_array( 'resume_count', $columns, true ) ) {
			$wpdb->query( "ALTER TABLE {$items_table} ADD COLUMN `resume_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Number of resume attempts'" );
		}
		if ( ! in_array( 'last_checkpoint_at', $columns, true ) ) {
			$wpdb->query( "ALTER TABLE {$items_table} ADD COLUMN `last_checkpoint_at` DATETIME NULL COMMENT 'Timestamp of last checkpoint save'" );
		}
		
		// Add indexes if they don't exist
		$indexes_result = $wpdb->get_results( "SHOW INDEX FROM {$items_table}", ARRAY_A );
		$index_names = [];
		if ( is_array( $indexes_result ) ) {
			$index_names = array_column( $indexes_result, 'Key_name' );
		}
		
		if ( ! in_array( 'idx_checkpoint_step', $index_names, true ) ) {
			$wpdb->query( "ALTER TABLE {$items_table} ADD INDEX `idx_checkpoint_step` (`checkpoint_step`)" );
		}
		if ( ! in_array( 'idx_resume_count', $index_names, true ) ) {
			$wpdb->query( "ALTER TABLE {$items_table} ADD INDEX `idx_resume_count` (`resume_count`)" );
		}
		
		// Add optimization indexes for cleanup queries (if they don't exist)
		if ( ! in_array( 'idx_updated_at', $index_names, true ) ) {
			$wpdb->query( "ALTER TABLE {$items_table} ADD INDEX `idx_updated_at` (`updated_at`)" );
			error_log( '[AEBG] Added idx_updated_at index to aebg_batch_items table' );
		}
		if ( ! in_array( 'idx_status_updated_at', $index_names, true ) ) {
			$wpdb->query( "ALTER TABLE {$items_table} ADD INDEX `idx_status_updated_at` (`status`, `updated_at`)" );
			error_log( '[AEBG] Added idx_status_updated_at composite index to aebg_batch_items table' );
		}
		
		// Add index on created_at for batches table
		$batches_table = $wpdb->prefix . 'aebg_batches';
		$batches_indexes_result = $wpdb->get_results( "SHOW INDEX FROM {$batches_table}", ARRAY_A );
		$batches_index_names = [];
		if ( is_array( $batches_indexes_result ) ) {
			$batches_index_names = array_column( $batches_indexes_result, 'Key_name' );
		}
		if ( ! in_array( 'idx_created_at', $batches_index_names, true ) ) {
			$wpdb->query( "ALTER TABLE {$batches_table} ADD INDEX `idx_created_at` (`created_at`)" );
			error_log( '[AEBG] Added idx_created_at index to aebg_batches table' );
		}
		
		// Add step-by-step action columns (for step-by-step architecture)
		if ( ! in_array( 'current_step', $columns, true ) ) {
			$wpdb->query( "ALTER TABLE {$items_table} ADD COLUMN `current_step` VARCHAR(50) NULL COMMENT 'Current step being executed (step_1, step_2, etc.)'" );
		}
		if ( ! in_array( 'step_progress', $columns, true ) ) {
			$wpdb->query( "ALTER TABLE {$items_table} ADD COLUMN `step_progress` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Step number (1-12) for progress tracking'" );
		}
		
		// Add indexes for step-by-step columns
		if ( ! in_array( 'idx_current_step', $index_names, true ) ) {
			$wpdb->query( "ALTER TABLE {$items_table} ADD INDEX `idx_current_step` (`current_step`)" );
		}
		if ( ! in_array( 'idx_step_progress', $index_names, true ) ) {
			$wpdb->query( "ALTER TABLE {$items_table} ADD INDEX `idx_step_progress` (`step_progress`)" );
		}
		
		// Enable step-by-step actions by default (preferred method)
		// update_option() will add the option if it doesn't exist, or update it if it does
		// This ensures step-by-step is enabled on fresh installs and when reactivating
		$current_value = get_option( 'aebg_use_step_by_step_actions' );
		if ( $current_value === false ) {
			// Option doesn't exist - add it with true
			add_option( 'aebg_use_step_by_step_actions', true, '', 'no' );
		} else {
			// Option exists - update it to true (enables step-by-step on reactivation)
			update_option( 'aebg_use_step_by_step_actions', true );
		}

		$sql_networks = "CREATE TABLE `{$wpdb->prefix}aebg_networks` (
			`id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			`network_key` VARCHAR(100) NOT NULL,
			`network_name` VARCHAR(255) NOT NULL,
			`network_type` VARCHAR(50) NOT NULL DEFAULT 'manual',
			`region` VARCHAR(50) NULL,
			`country` VARCHAR(10) NULL,
			`is_active` TINYINT(1) NOT NULL DEFAULT 1,
			`api_data` JSON NULL,
			`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			`updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (`id`),
			UNIQUE KEY `idx_network_key` (`network_key`),
			KEY `idx_network_type` (`network_type`),
			KEY `idx_region` (`region`),
			KEY `idx_country` (`country`),
			KEY `idx_is_active` (`is_active`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
		dbDelta( $sql_networks );

		$sql_affiliate_ids = "CREATE TABLE `{$wpdb->prefix}aebg_affiliate_ids` (
			`id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			`network_key` VARCHAR(100) NOT NULL,
			`affiliate_id` VARCHAR(255) NOT NULL,
			`user_id` BIGINT(20) UNSIGNED NOT NULL DEFAULT 1,
			`is_active` TINYINT(1) NOT NULL DEFAULT 1,
			`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			`updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (`id`),
			UNIQUE KEY `idx_network_user` (`network_key`, `user_id`),
			KEY `idx_network_key` (`network_key`),
			KEY `idx_user_id` (`user_id`),
			KEY `idx_is_active` (`is_active`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
		dbDelta( $sql_affiliate_ids );

		// Create comparison data table
		$sql_comparisons = "CREATE TABLE `{$wpdb->prefix}aebg_comparisons` (
			`id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			`user_id` BIGINT(20) UNSIGNED NOT NULL,
			`post_id` BIGINT(20) UNSIGNED NULL,
			`product_id` VARCHAR(255) NOT NULL,
			`comparison_name` VARCHAR(255) NOT NULL,
			`comparison_data` JSON NOT NULL,
			`status` VARCHAR(20) NOT NULL DEFAULT 'active',
			`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			`updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (`id`),
			KEY `idx_user_id` (`user_id`),
			KEY `idx_post_id` (`post_id`),
			KEY `idx_product_id` (`product_id`),
			KEY `idx_status` (`status`),
			KEY `idx_updated_at` (`updated_at`),
			KEY `idx_product_status_updated` (`product_id`, `status`, `updated_at`),
			KEY `idx_user_post_status` (`user_id`, `post_id`, `status`),
			UNIQUE KEY `idx_user_post_product` (`user_id`, `post_id`, `product_id`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
		dbDelta( $sql_comparisons );
		
		// Add performance indexes if they don't exist (for upgrades)
		$comparisons_table = $wpdb->prefix . 'aebg_comparisons';
		$comparisons_indexes = $wpdb->get_results( "SHOW INDEX FROM {$comparisons_table}", ARRAY_A );
		$comparisons_index_names = [];
		if ( is_array( $comparisons_indexes ) ) {
			$comparisons_index_names = array_column( $comparisons_indexes, 'Key_name' );
		}
		
		// Add composite index for optimized queries in Shortcodes.php
		if ( ! in_array( 'idx_product_status_updated', $comparisons_index_names, true ) ) {
			$wpdb->query( "ALTER TABLE {$comparisons_table} ADD INDEX `idx_product_status_updated` (`product_id`, `status`, `updated_at`)" );
		}
		if ( ! in_array( 'idx_user_post_status', $comparisons_index_names, true ) ) {
			$wpdb->query( "ALTER TABLE {$comparisons_table} ADD INDEX `idx_user_post_status` (`user_id`, `post_id`, `status`)" );
		}
		if ( ! in_array( 'idx_updated_at', $comparisons_index_names, true ) ) {
			$wpdb->query( "ALTER TABLE {$comparisons_table} ADD INDEX `idx_updated_at` (`updated_at`)" );
		}

		// Create Trustpilot ratings cache table
		$sql_trustpilot = "CREATE TABLE `{$wpdb->prefix}aebg_trustpilot_ratings` (
			`id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			`merchant_name` VARCHAR(255) NOT NULL,
			`rating` DECIMAL(3,1) NULL,
			`review_count` INT(11) NULL,
			`last_updated` DATETIME NULL,
			`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (`id`),
			UNIQUE KEY `idx_merchant_name` (`merchant_name`),
			KEY `idx_last_updated` (`last_updated`),
			KEY `idx_rating` (`rating`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
		dbDelta( $sql_trustpilot );

		// Create Network API Credentials table
		$sql_network_credentials = "CREATE TABLE `{$wpdb->prefix}aebg_network_api_credentials` (
			`id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			`network_key` VARCHAR(100) NOT NULL,
			`user_id` BIGINT(20) UNSIGNED NOT NULL DEFAULT 1,
			`credential_type` VARCHAR(50) NOT NULL COMMENT 'analytics_key, feed_key, reporting_key, access_token, refresh_token, secret_key, etc.',
			`credential_value` TEXT NOT NULL COMMENT 'Encrypted credential value',
			`credential_label` VARCHAR(255) NULL COMMENT 'User-friendly label',
			`is_encrypted` TINYINT(1) NOT NULL DEFAULT 1,
			`is_active` TINYINT(1) NOT NULL DEFAULT 1,
			`last_used` DATETIME NULL,
			`last_validated` DATETIME NULL,
			`validation_status` VARCHAR(50) NULL COMMENT 'valid, invalid, expired, unknown',
			`expires_at` DATETIME NULL COMMENT 'For OAuth tokens that expire',
			`metadata` JSON NULL COMMENT 'Additional network-specific metadata',
			`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			`updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (`id`),
			UNIQUE KEY `idx_network_user_type` (`network_key`, `user_id`, `credential_type`),
			KEY `idx_network_key` (`network_key`),
			KEY `idx_user_id` (`user_id`),
			KEY `idx_credential_type` (`credential_type`),
			KEY `idx_is_active` (`is_active`),
			KEY `idx_validation_status` (`validation_status`),
			KEY `idx_expires_at` (`expires_at`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
		dbDelta( $sql_network_credentials );

		// Create Network Analytics cache table
		$sql_network_analytics = "CREATE TABLE `{$wpdb->prefix}aebg_network_analytics` (
			`id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			`network_key` VARCHAR(100) NOT NULL,
			`user_id` BIGINT(20) UNSIGNED NOT NULL DEFAULT 1,
			`data_type` VARCHAR(50) NOT NULL COMMENT 'saldo, indtjening, programstat, vissalg, klikoversigt, etc.',
			`date_from` DATE NULL,
			`date_to` DATE NULL,
			`data` JSON NOT NULL,
			`cached_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			`expires_at` DATETIME NOT NULL,
			`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			`updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (`id`),
			UNIQUE KEY `idx_network_user_type_dates` (`network_key`, `user_id`, `data_type`, `date_from`, `date_to`),
			KEY `idx_network_key` (`network_key`),
			KEY `idx_user_id` (`user_id`),
			KEY `idx_data_type` (`data_type`),
			KEY `idx_expires_at` (`expires_at`),
			KEY `idx_cached_at` (`cached_at`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
		dbDelta( $sql_network_analytics );

		// Create Network Clicks table (for storing synced clicks with duplicate prevention)
		$sql_network_clicks = "CREATE TABLE `{$wpdb->prefix}aebg_network_clicks` (
			`id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			`network_key` VARCHAR(100) NOT NULL,
			`user_id` BIGINT(20) UNSIGNED NOT NULL DEFAULT 1,
			`programid` VARCHAR(50) NULL,
			`programnavn` VARCHAR(255) NULL,
			`dato` DATE NULL COMMENT 'Click date (DD-MM-YYYY format from API)',
			`tid` TIME NULL COMMENT 'Click time (HH:MM format from API)',
			`url` TEXT NULL,
			`uid` VARCHAR(255) NULL,
			`uid2` VARCHAR(255) NULL,
			`salg` VARCHAR(10) NULL COMMENT 'Yes/No',
			`click_datetime` DATETIME NULL COMMENT 'Combined date and time for sorting',
			`raw_data` JSON NULL COMMENT 'Full click data from API',
			`synced_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			`updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (`id`),
			UNIQUE KEY `idx_network_click_unique` (`network_key`, `user_id`, `programid`, `dato`, `tid`),
			KEY `idx_network_key` (`network_key`),
			KEY `idx_user_id` (`user_id`),
			KEY `idx_programid` (`programid`),
			KEY `idx_dato` (`dato`),
			KEY `idx_click_datetime` (`click_datetime`),
			KEY `idx_synced_at` (`synced_at`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
		dbDelta( $sql_network_clicks );

		// Create Network Sales table (for storing synced sales/leads with duplicate prevention)
		$sql_network_sales = "CREATE TABLE `{$wpdb->prefix}aebg_network_sales` (
			`id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			`network_key` VARCHAR(100) NOT NULL,
			`user_id` BIGINT(20) UNSIGNED NOT NULL DEFAULT 1,
			`konvid` VARCHAR(100) NULL COMMENT 'Conversion ID (unique identifier)',
			`type` VARCHAR(50) NULL COMMENT 'salg, lead, etc.',
			`programid` VARCHAR(50) NULL,
			`program` VARCHAR(255) NULL COMMENT 'Program name',
			`dato` DATE NULL COMMENT 'Sale date (DD-MM-YYYY format from API)',
			`tidspunkt` TIME NULL COMMENT 'Sale time (HH:MM:SS format from API)',
			`ordrenr` VARCHAR(255) NULL COMMENT 'Order number',
			`varenr` VARCHAR(255) NULL COMMENT 'Product number',
			`omsaetning` DECIMAL(10,2) NULL COMMENT 'Revenue/turnover',
			`provision` DECIMAL(10,2) NULL COMMENT 'Commission',
			`url` TEXT NULL,
			`uid` VARCHAR(255) NULL,
			`uid2` VARCHAR(255) NULL,
			`valuta` VARCHAR(10) NULL COMMENT 'Currency code',
			`is_cancelled` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Whether this sale has been cancelled',
			`cancelled_at` DATETIME NULL COMMENT 'When this sale was marked as cancelled',
			`sale_datetime` DATETIME NULL COMMENT 'Combined date and time for sorting',
			`raw_data` JSON NULL COMMENT 'Full sale data from API',
			`synced_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			`updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (`id`),
			UNIQUE KEY `idx_network_sale_unique` (`network_key`, `user_id`, `konvid`),
			KEY `idx_network_key` (`network_key`),
			KEY `idx_user_id` (`user_id`),
			KEY `idx_programid` (`programid`),
			KEY `idx_dato` (`dato`),
			KEY `idx_ordrenr` (`ordrenr`),
			KEY `idx_sale_datetime` (`sale_datetime`),
			KEY `idx_synced_at` (`synced_at`),
			KEY `idx_type` (`type`),
			KEY `idx_is_cancelled` (`is_cancelled`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
		dbDelta( $sql_network_sales );

		// Create Network Cancellations table (for storing synced cancellations with duplicate prevention)
		$sql_network_cancellations = "CREATE TABLE `{$wpdb->prefix}aebg_network_cancellations` (
			`id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			`network_key` VARCHAR(100) NOT NULL,
			`user_id` BIGINT(20) UNSIGNED NOT NULL DEFAULT 1,
			`convid` VARCHAR(100) NULL COMMENT 'Conversion ID (matches konvid in sales table)',
			`programid` VARCHAR(50) NULL,
			`program` VARCHAR(255) NULL COMMENT 'Program name',
			`dato` DATE NULL COMMENT 'Cancellation date (DD-MM-YYYY format from API)',
			`ordrenr` VARCHAR(255) NULL COMMENT 'Order number',
			`varenr` VARCHAR(255) NULL COMMENT 'Product number',
			`ordretotal` DECIMAL(10,2) NULL COMMENT 'Order total',
			`provision` DECIMAL(10,2) NULL COMMENT 'Commission that was cancelled',
			`uid` VARCHAR(255) NULL,
			`uid2` VARCHAR(255) NULL,
			`cancellation_datetime` DATETIME NULL COMMENT 'Combined date and time for sorting',
			`raw_data` JSON NULL COMMENT 'Full cancellation data from API',
			`synced_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			`updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (`id`),
			UNIQUE KEY `idx_network_cancellation_unique` (`network_key`, `user_id`, `convid`),
			KEY `idx_network_key` (`network_key`),
			KEY `idx_user_id` (`user_id`),
			KEY `idx_programid` (`programid`),
			KEY `idx_dato` (`dato`),
			KEY `idx_convid` (`convid`),
			KEY `idx_cancellation_datetime` (`cancellation_datetime`),
			KEY `idx_synced_at` (`synced_at`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
		dbDelta( $sql_network_cancellations );

		// ============================================
		// EMAIL MARKETING SYSTEM TABLES
		// ============================================
		
		// Create Email Lists table
		$sql_email_lists = "CREATE TABLE `{$wpdb->prefix}aebg_email_lists` (
			`id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			`post_id` BIGINT(20) UNSIGNED NULL COMMENT 'Associated post ID (NULL for global lists)',
			`list_name` VARCHAR(255) NOT NULL COMMENT 'List name',
			`list_type` VARCHAR(50) NOT NULL DEFAULT 'post' COMMENT 'post, global, category, tag',
			`list_key` VARCHAR(100) NOT NULL COMMENT 'Unique identifier for list',
			`description` TEXT NULL,
			`settings` JSON NULL COMMENT 'List-specific settings (opt-in type, etc.)',
			`subscriber_count` INT(11) NOT NULL DEFAULT 0 COMMENT 'Cached subscriber count',
			`is_active` TINYINT(1) NOT NULL DEFAULT 1,
			`user_id` BIGINT(20) UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Owner user ID',
			`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			`updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (`id`),
			UNIQUE KEY `idx_list_key` (`list_key`),
			KEY `idx_post_id` (`post_id`),
			KEY `idx_list_type` (`list_type`),
			KEY `idx_user_id` (`user_id`),
			KEY `idx_is_active` (`is_active`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
		dbDelta( $sql_email_lists );

		// Create Email Subscribers table
		$sql_email_subscribers = "CREATE TABLE `{$wpdb->prefix}aebg_email_subscribers` (
			`id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			`list_id` BIGINT(20) UNSIGNED NOT NULL,
			`email` VARCHAR(255) NOT NULL,
			`first_name` VARCHAR(100) NULL,
			`last_name` VARCHAR(100) NULL,
			`status` VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT 'pending, confirmed, unsubscribed, bounced',
			`source` VARCHAR(50) NULL COMMENT 'widget, modal, shortcode, import',
			`ip_address` VARCHAR(45) NULL COMMENT 'IPv4 or IPv6',
			`user_agent` TEXT NULL,
			`opt_in_token` VARCHAR(100) NULL COMMENT 'Double opt-in token',
			`opt_in_confirmed_at` DATETIME NULL,
			`unsubscribed_at` DATETIME NULL,
			`unsubscribe_token` VARCHAR(100) NULL,
			`metadata` JSON NULL COMMENT 'Additional subscriber data',
			`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			`updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (`id`),
			UNIQUE KEY `idx_list_email` (`list_id`, `email`),
			KEY `idx_email` (`email`),
			KEY `idx_status` (`status`),
			KEY `idx_opt_in_token` (`opt_in_token`),
			KEY `idx_unsubscribe_token` (`unsubscribe_token`),
			KEY `idx_created_at` (`created_at`),
			KEY `idx_subscriber_list_status` (`list_id`, `status`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
		dbDelta( $sql_email_subscribers );
		
		// Add FOREIGN KEY constraint separately (dbDelta doesn't handle them)
		$this->add_foreign_key_if_not_exists(
			"{$wpdb->prefix}aebg_email_subscribers",
			"fk_subscribers_list",
			"`list_id`",
			"{$wpdb->prefix}aebg_email_lists",
			"`id`",
			"ON DELETE CASCADE"
		);

		// Create Email Campaigns table
		$sql_email_campaigns = "CREATE TABLE `{$wpdb->prefix}aebg_email_campaigns` (
			`id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			`post_id` BIGINT(20) UNSIGNED NULL COMMENT 'Associated post ID',
			`campaign_name` VARCHAR(255) NOT NULL,
			`campaign_type` VARCHAR(50) NOT NULL COMMENT 'post_update, product_reorder, product_replace, new_post, manual',
			`trigger_event` VARCHAR(100) NULL COMMENT 'Event that triggered campaign',
			`template_id` BIGINT(20) UNSIGNED NULL COMMENT 'Template ID from aebg_email_templates',
			`subject` VARCHAR(255) NOT NULL,
			`content_html` LONGTEXT NULL,
			`content_text` LONGTEXT NULL,
			`status` VARCHAR(20) NOT NULL DEFAULT 'draft' COMMENT 'draft, scheduled, sending, sent, paused, cancelled',
			`scheduled_at` DATETIME NULL,
			`sent_at` DATETIME NULL,
			`list_ids` JSON NULL COMMENT 'Array of list IDs to send to',
			`settings` JSON NULL COMMENT 'Campaign settings (from_name, from_email, etc.)',
			`stats` JSON NULL COMMENT 'Campaign statistics (sent, opened, clicked, etc.)',
			`user_id` BIGINT(20) UNSIGNED NOT NULL DEFAULT 1,
			`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			`updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (`id`),
			KEY `idx_post_id` (`post_id`),
			KEY `idx_campaign_type` (`campaign_type`),
			KEY `idx_status` (`status`),
			KEY `idx_scheduled_at` (`scheduled_at`),
			KEY `idx_user_id` (`user_id`),
			KEY `idx_campaign_post_status` (`post_id`, `status`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
		dbDelta( $sql_email_campaigns );

		// Create Email Templates table
		$sql_email_templates = "CREATE TABLE `{$wpdb->prefix}aebg_email_templates` (
			`id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			`template_name` VARCHAR(255) NOT NULL,
			`template_type` VARCHAR(50) NOT NULL COMMENT 'post_update, product_reorder, product_replace, new_post, custom',
			`subject_template` VARCHAR(255) NOT NULL COMMENT 'Subject with variables like {post_title}',
			`content_html` LONGTEXT NOT NULL COMMENT 'HTML template with variables',
			`content_text` LONGTEXT NULL COMMENT 'Plain text version',
			`variables` JSON NULL COMMENT 'Available template variables',
			`is_default` TINYINT(1) NOT NULL DEFAULT 0,
			`is_active` TINYINT(1) NOT NULL DEFAULT 1,
			`user_id` BIGINT(20) UNSIGNED NOT NULL DEFAULT 1,
			`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			`updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (`id`),
			KEY `idx_template_type` (`template_type`),
			KEY `idx_is_default` (`is_default`),
			KEY `idx_user_id` (`user_id`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
		dbDelta( $sql_email_templates );

		// Create Email Queue table
		$sql_email_queue = "CREATE TABLE `{$wpdb->prefix}aebg_email_queue` (
			`id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			`campaign_id` BIGINT(20) UNSIGNED NOT NULL,
			`subscriber_id` BIGINT(20) UNSIGNED NOT NULL,
			`email` VARCHAR(255) NOT NULL,
			`subject` VARCHAR(255) NOT NULL,
			`content_html` LONGTEXT NOT NULL,
			`content_text` LONGTEXT NULL,
			`status` VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT 'pending, sending, sent, failed, bounced',
			`scheduled_at` DATETIME NOT NULL,
			`sent_at` DATETIME NULL,
			`error_message` TEXT NULL,
			`retry_count` INT(11) NOT NULL DEFAULT 0,
			`max_retries` INT(11) NOT NULL DEFAULT 3,
			`metadata` JSON NULL,
			`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			`updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (`id`),
			KEY `idx_campaign_id` (`campaign_id`),
			KEY `idx_subscriber_id` (`subscriber_id`),
			KEY `idx_status` (`status`),
			KEY `idx_scheduled_at` (`scheduled_at`),
			KEY `idx_email` (`email`),
			KEY `idx_queue_status_scheduled` (`status`, `scheduled_at`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
		dbDelta( $sql_email_queue );
		
		// Add FOREIGN KEY constraints separately
		$this->add_foreign_key_if_not_exists(
			"{$wpdb->prefix}aebg_email_queue",
			"fk_queue_campaign",
			"`campaign_id`",
			"{$wpdb->prefix}aebg_email_campaigns",
			"`id`",
			"ON DELETE CASCADE"
		);
		$this->add_foreign_key_if_not_exists(
			"{$wpdb->prefix}aebg_email_queue",
			"fk_queue_subscriber",
			"`subscriber_id`",
			"{$wpdb->prefix}aebg_email_subscribers",
			"`id`",
			"ON DELETE CASCADE"
		);

		// Create Email Tracking table
		$sql_email_tracking = "CREATE TABLE `{$wpdb->prefix}aebg_email_tracking` (
			`id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			`queue_id` BIGINT(20) UNSIGNED NOT NULL,
			`campaign_id` BIGINT(20) UNSIGNED NOT NULL,
			`subscriber_id` BIGINT(20) UNSIGNED NOT NULL,
			`event_type` VARCHAR(50) NOT NULL COMMENT 'sent, delivered, opened, clicked, bounced, unsubscribed, complained',
			`event_data` JSON NULL COMMENT 'Additional event data (link URL for clicks, bounce reason, etc.)',
			`ip_address` VARCHAR(45) NULL,
			`user_agent` TEXT NULL,
			`device_type` VARCHAR(20) NULL COMMENT 'desktop, mobile, tablet',
			`email_client` VARCHAR(50) NULL COMMENT 'gmail, outlook, apple-mail, etc.',
			`location` VARCHAR(100) NULL COMMENT 'Country/city if IP geolocation enabled',
			`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (`id`),
			KEY `idx_queue_id` (`queue_id`),
			KEY `idx_campaign_id` (`campaign_id`),
			KEY `idx_subscriber_id` (`subscriber_id`),
			KEY `idx_event_type` (`event_type`),
			KEY `idx_created_at` (`created_at`),
			KEY `idx_campaign_event` (`campaign_id`, `event_type`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
		dbDelta( $sql_email_tracking );
		
		// Add FOREIGN KEY constraint separately
		$this->add_foreign_key_if_not_exists(
			"{$wpdb->prefix}aebg_email_tracking",
			"fk_tracking_queue",
			"`queue_id`",
			"{$wpdb->prefix}aebg_email_queue",
			"`id`",
			"ON DELETE CASCADE"
		);

		// Create Email Imports table
		$sql_email_imports = "CREATE TABLE `{$wpdb->prefix}aebg_email_imports` (
			`id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			`list_id` BIGINT(20) UNSIGNED NULL COMMENT 'Target list ID',
			`file_path` VARCHAR(255) NOT NULL,
			`file_name` VARCHAR(255) NOT NULL,
			`total_rows` INT(11) NOT NULL DEFAULT 0,
			`processed_rows` INT(11) NOT NULL DEFAULT 0,
			`successful_rows` INT(11) NOT NULL DEFAULT 0,
			`failed_rows` INT(11) NOT NULL DEFAULT 0,
			`status` VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT 'pending, processing, completed, failed',
			`column_mapping` JSON NULL COMMENT 'CSV column to field mapping',
			`options` JSON NULL COMMENT 'Import options (skip duplicates, opt-in status, etc.)',
			`error_log` TEXT NULL COMMENT 'Errors encountered during import',
			`user_id` BIGINT(20) UNSIGNED NOT NULL DEFAULT 1,
			`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			`updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (`id`),
			KEY `idx_list_id` (`list_id`),
			KEY `idx_status` (`status`),
			KEY `idx_user_id` (`user_id`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
		dbDelta( $sql_email_imports );
		
		// Add FOREIGN KEY constraint separately
		$this->add_foreign_key_if_not_exists(
			"{$wpdb->prefix}aebg_email_imports",
			"fk_imports_list",
			"`list_id`",
			"{$wpdb->prefix}aebg_email_lists",
			"`id`",
			"ON DELETE SET NULL"
		);
		
		// Create default email templates for automated campaigns
		$this->create_default_email_templates();

		// ============================================
		// END EMAIL MARKETING SYSTEM TABLES
		// ============================================

		// Create Competitor Tracking tables
		$sql_competitors = "CREATE TABLE `{$wpdb->prefix}aebg_competitors` (
			`id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			`user_id` BIGINT(20) UNSIGNED NOT NULL,
			`name` VARCHAR(255) NOT NULL COMMENT 'Competitor name/identifier',
			`url` VARCHAR(500) NOT NULL COMMENT 'Full URL to monitor',
			`scraping_interval` INT(11) NOT NULL DEFAULT 3600 COMMENT 'Interval in seconds (default: 1 hour)',
			`is_active` TINYINT(1) NOT NULL DEFAULT 1,
			`last_scraped_at` DATETIME NULL COMMENT 'Last successful scrape timestamp',
			`next_scrape_at` DATETIME NULL COMMENT 'Next scheduled scrape',
			`scrape_count` INT(11) NOT NULL DEFAULT 0 COMMENT 'Total number of scrapes',
			`error_count` INT(11) NOT NULL DEFAULT 0 COMMENT 'Consecutive error count',
			`last_error` TEXT NULL COMMENT 'Last error message',
			`ai_prompt_template` TEXT NULL COMMENT 'Custom AI prompt for analysis',
			`extraction_config` JSON NULL COMMENT 'Custom extraction rules (selectors, patterns)',
			`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			`updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (`id`),
			UNIQUE KEY `idx_url_unique` (`url`(255)),
			KEY `idx_user_id` (`user_id`),
			KEY `idx_is_active` (`is_active`),
			KEY `idx_next_scrape_at` (`next_scrape_at`),
			KEY `idx_last_scraped_at` (`last_scraped_at`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
		dbDelta( $sql_competitors );

		$sql_competitor_scrapes = "CREATE TABLE `{$wpdb->prefix}aebg_competitor_scrapes` (
			`id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			`competitor_id` BIGINT(20) UNSIGNED NOT NULL,
			`status` VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT 'pending, processing, completed, failed',
			`scraped_html` LONGTEXT NULL COMMENT 'Raw HTML content (optional, for debugging)',
			`scraped_content` LONGTEXT NULL COMMENT 'Cleaned/extracted content',
			`ai_analysis` JSON NULL COMMENT 'AI-extracted product data',
			`product_count` INT(11) NOT NULL DEFAULT 0 COMMENT 'Number of products found',
			`error_message` TEXT NULL,
			`processing_time` DECIMAL(10,3) NULL COMMENT 'Processing time in seconds',
			`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			`completed_at` DATETIME NULL,
			PRIMARY KEY (`id`),
			KEY `idx_competitor_id` (`competitor_id`),
			KEY `idx_status` (`status`),
			KEY `idx_created_at` (`created_at`),
			KEY `idx_competitor_status` (`competitor_id`, `status`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
		dbDelta( $sql_competitor_scrapes );

		$sql_competitor_products = "CREATE TABLE `{$wpdb->prefix}aebg_competitor_products` (
			`id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			`competitor_id` BIGINT(20) UNSIGNED NOT NULL,
			`scrape_id` BIGINT(20) UNSIGNED NOT NULL,
			`product_name` VARCHAR(500) NOT NULL,
			`product_url` VARCHAR(1000) NULL COMMENT 'Product page URL if available',
			`position` INT(11) NOT NULL COMMENT 'Position in list (1-based)',
			`previous_position` INT(11) NULL COMMENT 'Position from previous scrape',
			`position_change` INT(11) NULL COMMENT 'Change in position (negative = moved up)',
			`is_new` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'New product (not in previous scrape)',
			`is_removed` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Removed from list (in previous but not current)',
			`product_data` JSON NULL COMMENT 'Additional product metadata (price, rating, etc.)',
			`extracted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (`id`),
			KEY `idx_competitor_id` (`competitor_id`),
			KEY `idx_scrape_id` (`scrape_id`),
			KEY `idx_position` (`position`),
			KEY `idx_product_name` (`product_name`(255)),
			KEY `idx_competitor_scrape` (`competitor_id`, `scrape_id`),
			KEY `idx_is_new` (`is_new`),
			KEY `idx_is_removed` (`is_removed`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
		dbDelta( $sql_competitor_products );

		$sql_competitor_changes = "CREATE TABLE `{$wpdb->prefix}aebg_competitor_changes` (
			`id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			`competitor_id` BIGINT(20) UNSIGNED NOT NULL,
			`scrape_id` BIGINT(20) UNSIGNED NOT NULL,
			`change_type` VARCHAR(50) NOT NULL COMMENT 'position_change, new_product, removed_product, major_reshuffle',
			`product_name` VARCHAR(500) NULL COMMENT 'Product affected (if applicable)',
			`old_value` VARCHAR(255) NULL COMMENT 'Previous value (position, etc.)',
			`new_value` VARCHAR(255) NULL COMMENT 'New value',
			`change_severity` VARCHAR(20) NOT NULL DEFAULT 'medium' COMMENT 'low, medium, high, critical',
			`is_notified` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Whether notification was sent',
			`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (`id`),
			KEY `idx_competitor_id` (`competitor_id`),
			KEY `idx_scrape_id` (`scrape_id`),
			KEY `idx_change_type` (`change_type`),
			KEY `idx_change_severity` (`change_severity`),
			KEY `idx_is_notified` (`is_notified`),
			KEY `idx_created_at` (`created_at`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
		dbDelta( $sql_competitor_changes );

		// Create Prompt Templates table
		$sql_prompt_templates = "CREATE TABLE `{$wpdb->prefix}aebg_prompt_templates` (
			`id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			`user_id` BIGINT(20) UNSIGNED NOT NULL COMMENT 'Template creator',
			`name` VARCHAR(255) NOT NULL COMMENT 'Template name',
			`description` TEXT NULL COMMENT 'Template description',
			`prompt` LONGTEXT NOT NULL COMMENT 'The prompt text',
			`category` VARCHAR(100) NULL DEFAULT 'general' COMMENT 'Template category',
			`tags` JSON NULL COMMENT 'Array of tags for organization',
			`widget_types` JSON NULL COMMENT 'Compatible widget types (null = all)',
			`is_public` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Share with other users',
			`usage_count` INT(11) NOT NULL DEFAULT 0 COMMENT 'Number of times used',
			`last_used_at` DATETIME NULL COMMENT 'Last usage timestamp',
			`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			`updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (`id`),
			KEY `idx_user_id` (`user_id`),
			KEY `idx_category` (`category`),
			KEY `idx_is_public` (`is_public`),
			KEY `idx_created_at` (`created_at`),
			FULLTEXT KEY `idx_search` (`name`, `description`, `prompt`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
		dbDelta( $sql_prompt_templates );

		// Add custom capability
		add_role( 'aebg_generator', 'AEBG Generator', get_role( 'editor' )->capabilities );
		$editor_role = get_role( 'editor' );
		$editor_role->add_cap( 'aebg_generate_content' );
		$admin_role = get_role( 'administrator' );
		$admin_role->add_cap( 'aebg_generate_content' );
		
		// Clean up any existing NULL values in batch tables
		$wpdb->query( "UPDATE {$wpdb->prefix}aebg_batches SET processed_items = 0 WHERE processed_items IS NULL" );
		$wpdb->query( "UPDATE {$wpdb->prefix}aebg_batches SET failed_items = 0 WHERE failed_items IS NULL" );
		$wpdb->query( "UPDATE {$wpdb->prefix}aebg_batches SET total_items = 0 WHERE total_items IS NULL" );

		// Create API Usage Tracking table
		$sql_api_usage = "CREATE TABLE `{$wpdb->prefix}aebg_api_usage` (
			`id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			`batch_id` BIGINT(20) UNSIGNED NULL COMMENT 'Associated batch (if applicable)',
			`batch_item_id` BIGINT(20) UNSIGNED NULL COMMENT 'Associated batch item (if applicable)',
			`post_id` BIGINT(20) UNSIGNED NULL COMMENT 'Generated post ID',
			`user_id` BIGINT(20) UNSIGNED NOT NULL COMMENT 'User who triggered the request',
			`model` VARCHAR(50) NOT NULL COMMENT 'AI model used (gpt-3.5-turbo, gpt-4, etc.)',
			`prompt_tokens` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Prompt tokens used',
			`completion_tokens` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Completion tokens used',
			`total_tokens` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Total tokens used',
			`input_cost` DECIMAL(10,6) NOT NULL DEFAULT 0.000000 COMMENT 'Cost for input tokens',
			`output_cost` DECIMAL(10,6) NOT NULL DEFAULT 0.000000 COMMENT 'Cost for output tokens',
			`total_cost` DECIMAL(10,6) NOT NULL DEFAULT 0.000000 COMMENT 'Total cost for this request',
			`request_type` VARCHAR(50) NOT NULL DEFAULT 'generation' COMMENT 'Type: generation, image, analysis, etc.',
			`field_id` VARCHAR(100) NULL COMMENT 'Elementor field ID if applicable',
			`step_name` VARCHAR(100) NULL COMMENT 'Generation step (content, title, meta, etc.)',
			`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (`id`),
			KEY `idx_batch_id` (`batch_id`),
			KEY `idx_batch_item_id` (`batch_item_id`),
			KEY `idx_post_id` (`post_id`),
			KEY `idx_user_id` (`user_id`),
			KEY `idx_model` (`model`),
			KEY `idx_request_type` (`request_type`),
			KEY `idx_created_at` (`created_at`),
			KEY `idx_user_date` (`user_id`, `created_at`),
			KEY `idx_batch_date` (`batch_id`, `created_at`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
		dbDelta( $sql_api_usage );

		// Create Generation Activity table
		$sql_generation_activity = "CREATE TABLE `{$wpdb->prefix}aebg_generation_activity` (
			`id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			`batch_id` BIGINT(20) UNSIGNED NULL,
			`batch_item_id` BIGINT(20) UNSIGNED NULL,
			`post_id` BIGINT(20) UNSIGNED NULL COMMENT 'Generated post ID',
			`user_id` BIGINT(20) UNSIGNED NOT NULL,
			`template_id` BIGINT(20) UNSIGNED NULL,
			`status` VARCHAR(20) NOT NULL DEFAULT 'started' COMMENT 'started, completed, failed, cancelled',
			`started_at` DATETIME NOT NULL,
			`completed_at` DATETIME NULL,
			`duration_seconds` DECIMAL(10,3) NULL COMMENT 'Generation duration',
			`steps_completed` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Number of steps completed',
			`checkpoint_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Number of checkpoints saved',
			`resume_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Number of resume attempts',
			`memory_peak_mb` DECIMAL(10,2) NULL COMMENT 'Peak memory usage in MB',
			`content_length_words` INT UNSIGNED NULL COMMENT 'Word count of generated content',
			`total_cost` DECIMAL(10,6) NULL COMMENT 'Total cost for this generation',
			`total_tokens` INT UNSIGNED NULL COMMENT 'Total tokens used for this generation',
			`error_message` TEXT NULL,
			`metadata` JSON NULL COMMENT 'Additional metadata (steps, timings, etc.)',
			`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (`id`),
			KEY `idx_batch_id` (`batch_id`),
			KEY `idx_batch_item_id` (`batch_item_id`),
			KEY `idx_post_id` (`post_id`),
			KEY `idx_user_id` (`user_id`),
			KEY `idx_status` (`status`),
			KEY `idx_started_at` (`started_at`),
			KEY `idx_completed_at` (`completed_at`),
			KEY `idx_user_date` (`user_id`, `started_at`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
		dbDelta( $sql_generation_activity );

		// Create Product Replacements table
		$sql_product_replacements = "CREATE TABLE `{$wpdb->prefix}aebg_product_replacements` (
			`id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			`post_id` BIGINT(20) UNSIGNED NOT NULL COMMENT 'Post where replacement occurred',
			`user_id` BIGINT(20) UNSIGNED NOT NULL COMMENT 'User who performed replacement',
			`old_product_id` VARCHAR(255) NULL COMMENT 'Previous product ID',
			`old_product_name` VARCHAR(500) NULL COMMENT 'Previous product name',
			`new_product_id` VARCHAR(255) NOT NULL COMMENT 'New product ID',
			`new_product_name` VARCHAR(500) NOT NULL COMMENT 'New product name',
			`product_number` INT UNSIGNED NULL COMMENT 'Product position (1-based)',
			`replacement_type` VARCHAR(50) NOT NULL DEFAULT 'manual' COMMENT 'manual, auto, bulk',
			`reason` VARCHAR(255) NULL COMMENT 'Replacement reason if available',
			`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (`id`),
			KEY `idx_post_id` (`post_id`),
			KEY `idx_user_id` (`user_id`),
			KEY `idx_old_product_id` (`old_product_id`),
			KEY `idx_new_product_id` (`new_product_id`),
			KEY `idx_created_at` (`created_at`),
			KEY `idx_post_date` (`post_id`, `created_at`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
		dbDelta( $sql_product_replacements );
	}

	/**
	 * Ensure API usage table exists
	 * This method can be called on plugin load to ensure table exists
	 */
	public static function ensureApiUsageTable() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		
		$table_name = $wpdb->prefix . 'aebg_api_usage';
		
		// Check if table exists
		$table_exists = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s",
			DB_NAME,
			$table_name
		) );
		
		if ( ! $table_exists ) {
			// Create the table
			$sql_api_usage = "CREATE TABLE `{$wpdb->prefix}aebg_api_usage` (
				`id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				`batch_id` BIGINT(20) UNSIGNED NULL COMMENT 'Associated batch (if applicable)',
				`batch_item_id` BIGINT(20) UNSIGNED NULL COMMENT 'Associated batch item (if applicable)',
				`post_id` BIGINT(20) UNSIGNED NULL COMMENT 'Generated post ID',
				`user_id` BIGINT(20) UNSIGNED NOT NULL COMMENT 'User who triggered the request',
				`model` VARCHAR(50) NOT NULL COMMENT 'AI model used (gpt-3.5-turbo, gpt-4, etc.)',
				`prompt_tokens` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Prompt tokens used',
				`completion_tokens` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Completion tokens used',
				`total_tokens` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Total tokens used',
				`input_cost` DECIMAL(10,6) NOT NULL DEFAULT 0.000000 COMMENT 'Cost for input tokens',
				`output_cost` DECIMAL(10,6) NOT NULL DEFAULT 0.000000 COMMENT 'Cost for output tokens',
				`total_cost` DECIMAL(10,6) NOT NULL DEFAULT 0.000000 COMMENT 'Total cost for this request',
				`request_type` VARCHAR(50) NOT NULL DEFAULT 'generation' COMMENT 'Type: generation, image, analysis, etc.',
				`field_id` VARCHAR(100) NULL COMMENT 'Elementor field ID if applicable',
				`step_name` VARCHAR(100) NULL COMMENT 'Generation step (content, title, meta, etc.)',
				`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (`id`),
				KEY `idx_batch_id` (`batch_id`),
				KEY `idx_batch_item_id` (`batch_item_id`),
				KEY `idx_post_id` (`post_id`),
				KEY `idx_user_id` (`user_id`),
				KEY `idx_model` (`model`),
				KEY `idx_request_type` (`request_type`),
				KEY `idx_created_at` (`created_at`),
				KEY `idx_user_date` (`user_id`, `created_at`),
				KEY `idx_batch_date` (`batch_id`, `created_at`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
			
			dbDelta( $sql_api_usage );
			error_log( '[AEBG] Created aebg_api_usage table' );
		}
	}

	/**
	 * Ensure core Email Marketing tables exist (lists, subscribers, campaigns, templates, queue, tracking, imports)
	 * This can safely be called on every request; it only creates tables if they are missing.
	 *
	 * Note: Foreign key constraints are added on initial activation only. When recreating tables here,
	 * lack of FKs is acceptable and avoids brittle cross-table dependencies during runtime recovery.
	 */
	public static function ensureEmailMarketingTables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// Helper closure to check if a table exists.
		$table_exists = function ( $table_name ) use ( $wpdb ) {
			return (bool) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s",
					DB_NAME,
					$table_name
				)
			);
		};

		// Email lists table.
		$email_lists_table = $wpdb->prefix . 'aebg_email_lists';
		if ( ! $table_exists( $email_lists_table ) ) {
			$sql_email_lists = "CREATE TABLE `{$wpdb->prefix}aebg_email_lists` (
				`id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				`post_id` BIGINT(20) UNSIGNED NULL COMMENT 'Associated post ID (NULL for global lists)',
				`list_name` VARCHAR(255) NOT NULL COMMENT 'List name',
				`list_type` VARCHAR(50) NOT NULL DEFAULT 'post' COMMENT 'post, global, category, tag',
				`list_key` VARCHAR(100) NOT NULL COMMENT 'Unique identifier for list',
				`description` TEXT NULL,
				`settings` JSON NULL COMMENT 'List-specific settings (opt-in type, etc.)',
				`subscriber_count` INT(11) NOT NULL DEFAULT 0 COMMENT 'Cached subscriber count',
				`is_active` TINYINT(1) NOT NULL DEFAULT 1,
				`user_id` BIGINT(20) UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Owner user ID',
				`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				`updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY (`id`),
				UNIQUE KEY `idx_list_key` (`list_key`),
				KEY `idx_post_id` (`post_id`),
				KEY `idx_list_type` (`list_type`),
				KEY `idx_user_id` (`user_id`),
				KEY `idx_is_active` (`is_active`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

			dbDelta( $sql_email_lists );
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[AEBG] Created aebg_email_lists table via ensureEmailMarketingTables' );
			}
		}

		// Email subscribers table.
		$email_subscribers_table = $wpdb->prefix . 'aebg_email_subscribers';
		if ( ! $table_exists( $email_subscribers_table ) ) {
			$sql_email_subscribers = "CREATE TABLE `{$wpdb->prefix}aebg_email_subscribers` (
				`id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				`list_id` BIGINT(20) UNSIGNED NOT NULL,
				`email` VARCHAR(255) NOT NULL,
				`first_name` VARCHAR(100) NULL,
				`last_name` VARCHAR(100) NULL,
				`status` VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT 'pending, confirmed, unsubscribed, bounced',
				`source` VARCHAR(50) NULL COMMENT 'widget, modal, shortcode, import',
				`ip_address` VARCHAR(45) NULL COMMENT 'IPv4 or IPv6',
				`user_agent` TEXT NULL,
				`opt_in_token` VARCHAR(100) NULL COMMENT 'Double opt-in token',
				`opt_in_confirmed_at` DATETIME NULL,
				`unsubscribed_at` DATETIME NULL,
				`unsubscribe_token` VARCHAR(100) NULL,
				`metadata` JSON NULL COMMENT 'Additional subscriber data',
				`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				`updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY (`id`),
				UNIQUE KEY `idx_list_email` (`list_id`, `email`),
				KEY `idx_email` (`email`),
				KEY `idx_status` (`status`),
				KEY `idx_opt_in_token` (`opt_in_token`),
				KEY `idx_unsubscribe_token` (`unsubscribe_token`),
				KEY `idx_created_at` (`created_at`),
				KEY `idx_subscriber_list_status` (`list_id`, `status`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

			dbDelta( $sql_email_subscribers );
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[AEBG] Created aebg_email_subscribers table via ensureEmailMarketingTables' );
			}
		}

		// Email campaigns table.
		$email_campaigns_table = $wpdb->prefix . 'aebg_email_campaigns';
		if ( ! $table_exists( $email_campaigns_table ) ) {
			$sql_email_campaigns = "CREATE TABLE `{$wpdb->prefix}aebg_email_campaigns` (
				`id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				`post_id` BIGINT(20) UNSIGNED NULL COMMENT 'Associated post ID',
				`campaign_name` VARCHAR(255) NOT NULL,
				`campaign_type` VARCHAR(50) NOT NULL COMMENT 'post_update, product_reorder, product_replace, new_post, manual',
				`trigger_event` VARCHAR(100) NULL COMMENT 'Event that triggered campaign',
				`template_id` BIGINT(20) UNSIGNED NULL COMMENT 'Template ID from aebg_email_templates',
				`subject` VARCHAR(255) NOT NULL,
				`content_html` LONGTEXT NULL,
				`content_text` LONGTEXT NULL,
				`status` VARCHAR(20) NOT NULL DEFAULT 'draft' COMMENT 'draft, scheduled, sending, sent, paused, cancelled',
				`scheduled_at` DATETIME NULL,
				`sent_at` DATETIME NULL,
				`list_ids` JSON NULL COMMENT 'Array of list IDs to send to',
				`settings` JSON NULL COMMENT 'Campaign settings (from_name, from_email, etc.)',
				`stats` JSON NULL COMMENT 'Campaign statistics (sent, opened, clicked, etc.)',
				`user_id` BIGINT(20) UNSIGNED NOT NULL DEFAULT 1,
				`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				`updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY (`id`),
				KEY `idx_post_id` (`post_id`),
				KEY `idx_campaign_type` (`campaign_type`),
				KEY `idx_status` (`status`),
				KEY `idx_scheduled_at` (`scheduled_at`),
				KEY `idx_user_id` (`user_id`),
				KEY `idx_campaign_post_status` (`post_id`, `status`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

			dbDelta( $sql_email_campaigns );
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[AEBG] Created aebg_email_campaigns table via ensureEmailMarketingTables' );
			}
		}

		// Email templates table.
		$email_templates_table = $wpdb->prefix . 'aebg_email_templates';
		if ( ! $table_exists( $email_templates_table ) ) {
			$sql_email_templates = "CREATE TABLE `{$wpdb->prefix}aebg_email_templates` (
				`id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				`template_name` VARCHAR(255) NOT NULL,
				`template_type` VARCHAR(50) NOT NULL COMMENT 'post_update, product_reorder, product_replace, new_post, custom',
				`subject_template` VARCHAR(255) NOT NULL COMMENT 'Subject with variables like {post_title}',
				`content_html` LONGTEXT NOT NULL COMMENT 'HTML template with variables',
				`content_text` LONGTEXT NULL COMMENT 'Plain text version',
				`variables` JSON NULL COMMENT 'Available template variables',
				`is_default` TINYINT(1) NOT NULL DEFAULT 0,
				`is_active` TINYINT(1) NOT NULL DEFAULT 1,
				`user_id` BIGINT(20) UNSIGNED NOT NULL DEFAULT 1,
				`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				`updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY (`id`),
				KEY `idx_template_type` (`template_type`),
				KEY `idx_is_default` (`is_default`),
				KEY `idx_user_id` (`user_id`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

			dbDelta( $sql_email_templates );
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[AEBG] Created aebg_email_templates table via ensureEmailMarketingTables' );
			}
		}

		// Email queue table.
		$email_queue_table = $wpdb->prefix . 'aebg_email_queue';
		if ( ! $table_exists( $email_queue_table ) ) {
			$sql_email_queue = "CREATE TABLE `{$wpdb->prefix}aebg_email_queue` (
				`id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				`campaign_id` BIGINT(20) UNSIGNED NOT NULL,
				`subscriber_id` BIGINT(20) UNSIGNED NOT NULL,
				`email` VARCHAR(255) NOT NULL,
				`subject` VARCHAR(255) NOT NULL,
				`content_html` LONGTEXT NOT NULL,
				`content_text` LONGTEXT NULL,
				`status` VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT 'pending, sending, sent, failed, bounced',
				`scheduled_at` DATETIME NOT NULL,
				`sent_at` DATETIME NULL,
				`error_message` TEXT NULL,
				`retry_count` INT(11) NOT NULL DEFAULT 0,
				`max_retries` INT(11) NOT NULL DEFAULT 3,
				`metadata` JSON NULL,
				`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				`updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY (`id`),
				KEY `idx_campaign_id` (`campaign_id`),
				KEY `idx_subscriber_id` (`subscriber_id`),
				KEY `idx_status` (`status`),
				KEY `idx_scheduled_at` (`scheduled_at`),
				KEY `idx_email` (`email`),
				KEY `idx_queue_status_scheduled` (`status`, `scheduled_at`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

			dbDelta( $sql_email_queue );
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[AEBG] Created aebg_email_queue table via ensureEmailMarketingTables' );
			}
		}

		// Email tracking table.
		$email_tracking_table = $wpdb->prefix . 'aebg_email_tracking';
		if ( ! $table_exists( $email_tracking_table ) ) {
			$sql_email_tracking = "CREATE TABLE `{$wpdb->prefix}aebg_email_tracking` (
				`id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				`queue_id` BIGINT(20) UNSIGNED NOT NULL,
				`campaign_id` BIGINT(20) UNSIGNED NOT NULL,
				`subscriber_id` BIGINT(20) UNSIGNED NOT NULL,
				`event_type` VARCHAR(50) NOT NULL COMMENT 'sent, delivered, opened, clicked, bounced, unsubscribed, complained',
				`event_data` JSON NULL COMMENT 'Additional event data (link URL for clicks, bounce reason, etc.)',
				`ip_address` VARCHAR(45) NULL,
				`user_agent` TEXT NULL,
				`device_type` VARCHAR(20) NULL COMMENT 'desktop, mobile, tablet',
				`email_client` VARCHAR(50) NULL COMMENT 'gmail, outlook, apple-mail, etc.',
				`location` VARCHAR(100) NULL COMMENT 'Country/city if IP geolocation enabled',
				`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (`id`),
				KEY `idx_queue_id` (`queue_id`),
				KEY `idx_campaign_id` (`campaign_id`),
				KEY `idx_subscriber_id` (`subscriber_id`),
				KEY `idx_event_type` (`event_type`),
				KEY `idx_created_at` (`created_at`),
				KEY `idx_campaign_event` (`campaign_id`, `event_type`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

			dbDelta( $sql_email_tracking );
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[AEBG] Created aebg_email_tracking table via ensureEmailMarketingTables' );
			}
		}

		// Email imports table.
		$email_imports_table = $wpdb->prefix . 'aebg_email_imports';
		if ( ! $table_exists( $email_imports_table ) ) {
			$sql_email_imports = "CREATE TABLE `{$wpdb->prefix}aebg_email_imports` (
				`id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				`list_id` BIGINT(20) UNSIGNED NULL COMMENT 'Target list ID',
				`file_path` VARCHAR(255) NOT NULL,
				`file_name` VARCHAR(255) NOT NULL,
				`total_rows` INT(11) NOT NULL DEFAULT 0,
				`processed_rows` INT(11) NOT NULL DEFAULT 0,
				`successful_rows` INT(11) NOT NULL DEFAULT 0,
				`failed_rows` INT(11) NOT NULL DEFAULT 0,
				`status` VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT 'pending, processing, completed, failed',
				`column_mapping` JSON NULL COMMENT 'CSV column to field mapping',
				`options` JSON NULL COMMENT 'Import options (skip duplicates, opt-in status, etc.)',
				`error_log` TEXT NULL COMMENT 'Errors encountered during import',
				`user_id` BIGINT(20) UNSIGNED NOT NULL DEFAULT 1,
				`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				`updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY (`id`),
				KEY `idx_list_id` (`list_id`),
				KEY `idx_status` (`status`),
				KEY `idx_user_id` (`user_id`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

			dbDelta( $sql_email_imports );
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[AEBG] Created aebg_email_imports table via ensureEmailMarketingTables' );
			}
		}
	}

	/**
	 * Ensure generation activity table exists
	 * This method can be called on plugin load to ensure table exists
	 */
	public static function ensureGenerationActivityTable() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		
		$table_name = $wpdb->prefix . 'aebg_generation_activity';
		
		// Check if table exists
		$table_exists = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s",
			DB_NAME,
			$table_name
		) );
		
		if ( ! $table_exists ) {
			// Create the table
			$sql_generation_activity = "CREATE TABLE `{$wpdb->prefix}aebg_generation_activity` (
				`id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				`batch_id` BIGINT(20) UNSIGNED NULL,
				`batch_item_id` BIGINT(20) UNSIGNED NULL,
				`post_id` BIGINT(20) UNSIGNED NULL COMMENT 'Generated post ID',
				`user_id` BIGINT(20) UNSIGNED NOT NULL,
				`template_id` BIGINT(20) UNSIGNED NULL,
				`status` VARCHAR(20) NOT NULL DEFAULT 'started' COMMENT 'started, completed, failed, cancelled',
				`started_at` DATETIME NOT NULL,
				`completed_at` DATETIME NULL,
				`duration_seconds` DECIMAL(10,3) NULL COMMENT 'Generation duration',
				`steps_completed` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Number of steps completed',
				`checkpoint_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Number of checkpoints saved',
				`resume_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Number of resume attempts',
				`memory_peak_mb` DECIMAL(10,2) NULL COMMENT 'Peak memory usage in MB',
				`content_length_words` INT UNSIGNED NULL COMMENT 'Word count of generated content',
				`total_cost` DECIMAL(10,6) NULL COMMENT 'Total cost for this generation',
				`total_tokens` INT UNSIGNED NULL COMMENT 'Total tokens used for this generation',
				`error_message` TEXT NULL,
				`metadata` JSON NULL COMMENT 'Additional metadata (steps, timings, etc.)',
				`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (`id`),
				KEY `idx_batch_id` (`batch_id`),
				KEY `idx_batch_item_id` (`batch_item_id`),
				KEY `idx_post_id` (`post_id`),
				KEY `idx_user_id` (`user_id`),
				KEY `idx_status` (`status`),
				KEY `idx_started_at` (`started_at`),
				KEY `idx_completed_at` (`completed_at`),
				KEY `idx_user_date` (`user_id`, `started_at`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
			
			dbDelta( $sql_generation_activity );
			error_log( '[AEBG] Created aebg_generation_activity table' );
		}
	}

	/**
	 * Ensure product replacements table exists
	 * This method can be called on plugin load to ensure table exists
	 */
	public static function ensureProductReplacementsTable() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		
		$table_name = $wpdb->prefix . 'aebg_product_replacements';
		
		// Check if table exists
		$table_exists = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s",
			DB_NAME,
			$table_name
		) );
		
		if ( ! $table_exists ) {
			// Create the table
			$sql_product_replacements = "CREATE TABLE `{$wpdb->prefix}aebg_product_replacements` (
				`id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				`post_id` BIGINT(20) UNSIGNED NOT NULL COMMENT 'Post where replacement occurred',
				`user_id` BIGINT(20) UNSIGNED NOT NULL COMMENT 'User who performed replacement',
				`old_product_id` VARCHAR(255) NULL COMMENT 'Previous product ID',
				`old_product_name` VARCHAR(500) NULL COMMENT 'Previous product name',
				`new_product_id` VARCHAR(255) NOT NULL COMMENT 'New product ID',
				`new_product_name` VARCHAR(500) NOT NULL COMMENT 'New product name',
				`product_number` INT UNSIGNED NULL COMMENT 'Product position (1-based)',
				`replacement_type` VARCHAR(50) NOT NULL DEFAULT 'manual' COMMENT 'manual, auto, bulk',
				`reason` VARCHAR(255) NULL COMMENT 'Replacement reason if available',
				`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (`id`),
				KEY `idx_post_id` (`post_id`),
				KEY `idx_user_id` (`user_id`),
				KEY `idx_old_product_id` (`old_product_id`),
				KEY `idx_new_product_id` (`new_product_id`),
				KEY `idx_created_at` (`created_at`),
				KEY `idx_post_date` (`post_id`, `created_at`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
			
			dbDelta( $sql_product_replacements );
			error_log( '[AEBG] Created aebg_product_replacements table' );
		}
	}

	/**
	 * Ensure product replacement checkpoints table exists
	 * This table stores checkpoint state for product replacement workflows
	 * Separate from aebg_product_replacements which tracks replacement history
	 * 
	 * @return void
	 */
	public static function ensureProductReplacementCheckpointsTable() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		
		$table_name = $wpdb->prefix . 'aebg_product_replacement_checkpoints';
		
		// Check if table exists
		$table_exists = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s",
			DB_NAME,
			$table_name
		) );
		
		if ( ! $table_exists ) {
			// Create the table
			$sql_product_replacement_checkpoints = "CREATE TABLE `{$wpdb->prefix}aebg_product_replacement_checkpoints` (
				`id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				`post_id` BIGINT(20) UNSIGNED NOT NULL COMMENT 'Post where replacement is occurring',
				`product_number` INT UNSIGNED NOT NULL COMMENT 'Product position (1-based)',
				`checkpoint_step` VARCHAR(50) NOT NULL COMMENT 'Current step identifier',
				`checkpoint_state` LONGTEXT NULL COMMENT 'JSON-encoded checkpoint state',
				`resume_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Number of resume attempts',
				`retry_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Number of retry attempts',
				`failed_at` DATETIME NULL COMMENT 'Timestamp when replacement failed',
				`error_message` TEXT NULL COMMENT 'Error message if failed',
				`failure_reason` VARCHAR(100) NULL COMMENT 'Failure reason code',
				`last_checkpoint_at` DATETIME NULL COMMENT 'Timestamp of last checkpoint save',
				`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				`updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY (`id`),
				UNIQUE KEY `idx_unique_replacement` (`post_id`, `product_number`),
				KEY `idx_checkpoint_step` (`checkpoint_step`),
				KEY `idx_resume_count` (`resume_count`),
				KEY `idx_retry_count` (`retry_count`),
				KEY `idx_last_checkpoint_at` (`last_checkpoint_at`),
				KEY `idx_failed_at` (`failed_at`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
			
			dbDelta( $sql_product_replacement_checkpoints );
			error_log( '[AEBG] Created aebg_product_replacement_checkpoints table' );
		} else {
			// Check if new columns exist (for upgrades)
			$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$table_name}" );
			
			// Add failed_at column if it doesn't exist
			if ( ! in_array( 'failed_at', $columns, true ) ) {
				$wpdb->query( "ALTER TABLE {$table_name} ADD COLUMN `failed_at` DATETIME NULL COMMENT 'Timestamp when replacement failed' AFTER `retry_count`" );
			}
			
			// Add error_message column if it doesn't exist
			if ( ! in_array( 'error_message', $columns, true ) ) {
				$wpdb->query( "ALTER TABLE {$table_name} ADD COLUMN `error_message` TEXT NULL COMMENT 'Error message if failed' AFTER `failed_at`" );
			}
			
			// Add failure_reason column if it doesn't exist
			if ( ! in_array( 'failure_reason', $columns, true ) ) {
				$wpdb->query( "ALTER TABLE {$table_name} ADD COLUMN `failure_reason` VARCHAR(100) NULL COMMENT 'Failure reason code' AFTER `error_message`" );
			}
			
			// Add indexes if they don't exist
			$indexes_result = $wpdb->get_results( "SHOW INDEX FROM {$table_name}", ARRAY_A );
			$index_names = [];
			if ( is_array( $indexes_result ) ) {
				$index_names = array_column( $indexes_result, 'Key_name' );
			}
			
			if ( ! in_array( 'idx_failed_at', $index_names, true ) ) {
				$wpdb->query( "ALTER TABLE {$table_name} ADD INDEX `idx_failed_at` (`failed_at`)" );
			}
		}
	}

	/**
	 * Ensure all dashboard tables exist
	 * Convenience method to ensure all dashboard-related tables are created
	 */
	public static function ensureDashboardTables() {
		self::ensureApiUsageTable();
		self::ensureGenerationActivityTable();
		self::ensureProductReplacementsTable();
		self::ensureProductReplacementCheckpointsTable();
	}

	/**
	 * Ensure prompt templates table exists
	 * This method can be called on plugin load to ensure table exists
	 */
	public static function ensurePromptTemplatesTable() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		
		$table_name = $wpdb->prefix . 'aebg_prompt_templates';
		
		// Check if table exists
		$table_exists = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s",
			DB_NAME,
			$table_name
		) );
		
		if ( ! $table_exists ) {
			// Create the table
			$sql_prompt_templates = "CREATE TABLE `{$wpdb->prefix}aebg_prompt_templates` (
				`id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				`user_id` BIGINT(20) UNSIGNED NOT NULL COMMENT 'Template creator',
				`name` VARCHAR(255) NOT NULL COMMENT 'Template name',
				`description` TEXT NULL COMMENT 'Template description',
				`prompt` LONGTEXT NOT NULL COMMENT 'The prompt text',
				`category` VARCHAR(100) NULL DEFAULT 'general' COMMENT 'Template category',
				`tags` JSON NULL COMMENT 'Array of tags for organization',
				`widget_types` JSON NULL COMMENT 'Compatible widget types (null = all)',
				`is_public` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Share with other users',
				`usage_count` INT(11) NOT NULL DEFAULT 0 COMMENT 'Number of times used',
				`last_used_at` DATETIME NULL COMMENT 'Last usage timestamp',
				`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				`updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY (`id`),
				KEY `idx_user_id` (`user_id`),
				KEY `idx_category` (`category`),
				KEY `idx_is_public` (`is_public`),
				KEY `idx_created_at` (`created_at`),
				FULLTEXT KEY `idx_search` (`name`, `description`, `prompt`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
			
			dbDelta( $sql_prompt_templates );
			error_log( '[AEBG] Created aebg_prompt_templates table' );
		}
	}
	
	/**
	 * Ensure checkpoint columns exist (for upgrades)
	 * This method can be called on plugin load to ensure columns exist
	 */
	public static function ensureCheckpointColumns() {
		global $wpdb;
		
		$items_table = $wpdb->prefix . 'aebg_batch_items';
		
		// Check if table exists
		$table_exists = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s",
			DB_NAME,
			$items_table
		) );
		
		if ( ! $table_exists ) {
			return; // Table doesn't exist yet, will be created on activation
		}
		
		// Get existing columns
		$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$items_table}" );
		
		// Add missing columns
		if ( ! in_array( 'checkpoint_state', $columns, true ) ) {
			$wpdb->query( "ALTER TABLE {$items_table} ADD COLUMN `checkpoint_state` LONGTEXT NULL COMMENT 'JSON-encoded checkpoint state for resume'" );
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[AEBG] Added checkpoint_state column to aebg_batch_items table' );
			}
		}
		if ( ! in_array( 'checkpoint_step', $columns, true ) ) {
			$wpdb->query( "ALTER TABLE {$items_table} ADD COLUMN `checkpoint_step` VARCHAR(50) NULL COMMENT 'Current step identifier for resume'" );
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[AEBG] Added checkpoint_step column to aebg_batch_items table' );
			}
		}
		if ( ! in_array( 'resume_count', $columns, true ) ) {
			$wpdb->query( "ALTER TABLE {$items_table} ADD COLUMN `resume_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Number of resume attempts'" );
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[AEBG] Added resume_count column to aebg_batch_items table' );
			}
		}
		if ( ! in_array( 'last_checkpoint_at', $columns, true ) ) {
			$wpdb->query( "ALTER TABLE {$items_table} ADD COLUMN `last_checkpoint_at` DATETIME NULL COMMENT 'Timestamp of last checkpoint save'" );
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[AEBG] Added last_checkpoint_at column to aebg_batch_items table' );
			}
		}
		
		// Add indexes if they don't exist
		$indexes_result = $wpdb->get_results( "SHOW INDEX FROM {$items_table}", ARRAY_A );
		$index_names = [];
		if ( is_array( $indexes_result ) ) {
			$index_names = array_column( $indexes_result, 'Key_name' );
		}
		
		if ( ! in_array( 'idx_checkpoint_step', $index_names, true ) ) {
			$wpdb->query( "ALTER TABLE {$items_table} ADD INDEX `idx_checkpoint_step` (`checkpoint_step`)" );
			error_log( '[AEBG] Added idx_checkpoint_step index to aebg_batch_items table' );
		}
		if ( ! in_array( 'idx_resume_count', $index_names, true ) ) {
			$wpdb->query( "ALTER TABLE {$items_table} ADD INDEX `idx_resume_count` (`resume_count`)" );
			error_log( '[AEBG] Added idx_resume_count index to aebg_batch_items table' );
		}
		
		// Add optimization indexes for cleanup queries (if they don't exist)
		if ( ! in_array( 'idx_updated_at', $index_names, true ) ) {
			$wpdb->query( "ALTER TABLE {$items_table} ADD INDEX `idx_updated_at` (`updated_at`)" );
			error_log( '[AEBG] Added idx_updated_at index to aebg_batch_items table' );
		}
		if ( ! in_array( 'idx_status_updated_at', $index_names, true ) ) {
			$wpdb->query( "ALTER TABLE {$items_table} ADD INDEX `idx_status_updated_at` (`status`, `updated_at`)" );
			error_log( '[AEBG] Added idx_status_updated_at composite index to aebg_batch_items table' );
		}
		
		// Add index on created_at for batches table
		$batches_table = $wpdb->prefix . 'aebg_batches';
		$batches_indexes_result = $wpdb->get_results( "SHOW INDEX FROM {$batches_table}", ARRAY_A );
		$batches_index_names = [];
		if ( is_array( $batches_indexes_result ) ) {
			$batches_index_names = array_column( $batches_indexes_result, 'Key_name' );
		}
		if ( ! in_array( 'idx_created_at', $batches_index_names, true ) ) {
			$wpdb->query( "ALTER TABLE {$batches_table} ADD INDEX `idx_created_at` (`created_at`)" );
			error_log( '[AEBG] Added idx_created_at index to aebg_batches table' );
		}
	}

	/**
	 * Add foreign key constraint if it doesn't exist
	 * 
	 * @param string $table_name Table name
	 * @param string $constraint_name Constraint name
	 * @param string $column Column name with backticks
	 * @param string $referenced_table Referenced table name
	 * @param string $referenced_column Referenced column name with backticks
	 * @param string $on_delete ON DELETE clause (e.g., "ON DELETE CASCADE")
	 * @return void
	 */
	private function add_foreign_key_if_not_exists( $table_name, $constraint_name, $column, $referenced_table, $referenced_column, $on_delete = '' ) {
		global $wpdb;
		
		// Check if constraint already exists
		// Note: information_schema stores table names without prefix in some setups
		$table_name_for_check = str_replace( $wpdb->prefix, '', $table_name );
		$constraint_exists = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) 
			 FROM information_schema.TABLE_CONSTRAINTS 
			 WHERE CONSTRAINT_SCHEMA = %s 
			 AND TABLE_NAME = %s 
			 AND CONSTRAINT_NAME = %s 
			 AND CONSTRAINT_TYPE = 'FOREIGN KEY'",
			DB_NAME,
			$table_name_for_check,
			$constraint_name
		) );
		
		// Also check with prefix (some setups store with prefix)
		if ( $constraint_exists == 0 ) {
			$constraint_exists = $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) 
				 FROM information_schema.TABLE_CONSTRAINTS 
				 WHERE CONSTRAINT_SCHEMA = %s 
				 AND TABLE_NAME = %s 
				 AND CONSTRAINT_NAME = %s 
				 AND CONSTRAINT_TYPE = 'FOREIGN KEY'",
				DB_NAME,
				$table_name,
				$constraint_name
			) );
		}
		
		if ( $constraint_exists > 0 ) {
			return; // Constraint already exists
		}
		
		// Check if table exists
		$table_exists = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) 
			 FROM information_schema.tables 
			 WHERE table_schema = %s 
			 AND table_name = %s",
			DB_NAME,
			$table_name
		) );
		
		if ( ! $table_exists ) {
			return; // Table doesn't exist yet
		}
		
		// Check if referenced table exists
		$referenced_table_exists = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) 
			 FROM information_schema.tables 
			 WHERE table_schema = %s 
			 AND table_name = %s",
			DB_NAME,
			$referenced_table
		) );
		
		if ( ! $referenced_table_exists ) {
			error_log( "[AEBG] Cannot add foreign key {$constraint_name}: Referenced table {$referenced_table} does not exist" );
			return; // Referenced table doesn't exist yet
		}
		
		// Add foreign key constraint
		$sql = "ALTER TABLE `{$table_name}` 
				ADD CONSTRAINT `{$constraint_name}` 
				FOREIGN KEY ({$column}) 
				REFERENCES `{$referenced_table}`({$referenced_column}) 
				{$on_delete}";
		
		$result = $wpdb->query( $sql );
		
		if ( $result === false ) {
			error_log( "[AEBG] Failed to add foreign key {$constraint_name}: " . $wpdb->last_error );
		} else {
			error_log( "[AEBG] Added foreign key {$constraint_name} to {$table_name}" );
		}
	}
	
	/**
	 * Create default email templates for automated campaigns
	 * 
	 * @return void
	 */
	private function create_default_email_templates() {
		global $wpdb;
		
		$table = $wpdb->prefix . 'aebg_email_templates';
		
		// Check if templates already exist
		$existing_count = $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		if ( $existing_count > 0 ) {
			return; // Templates already exist, don't overwrite
		}
		
		$site_name = get_bloginfo( 'name' );
		$site_url = home_url();
		
		// Default templates
		$templates = [
			[
				'template_name' => __( 'Post Update Notification', 'aebg' ),
				'template_type' => 'post_update',
				'subject_template' => __( 'Update: {post_title}', 'aebg' ),
				'content_html' => $this->get_default_template_html( 'post_update' ),
				'content_text' => $this->get_default_template_text( 'post_update' ),
				'variables' => wp_json_encode( ['post_title', 'post_url', 'post_excerpt', 'site_name', 'site_url', 'unsubscribe_url'] ),
				'is_default' => 1,
				'is_active' => 1,
			],
			[
				'template_name' => __( 'Product Reorder Notification', 'aebg' ),
				'template_type' => 'product_reorder',
				'subject_template' => __( 'Products Reordered: {post_title}', 'aebg' ),
				'content_html' => $this->get_default_template_html( 'product_reorder' ),
				'content_text' => $this->get_default_template_text( 'product_reorder' ),
				'variables' => wp_json_encode( ['post_title', 'post_url', 'product_list', 'site_name', 'site_url', 'unsubscribe_url'] ),
				'is_default' => 1,
				'is_active' => 1,
			],
			[
				'template_name' => __( 'Product Replacement Notification', 'aebg' ),
				'template_type' => 'product_replace',
				'subject_template' => __( 'New Product Added: {post_title}', 'aebg' ),
				'content_html' => $this->get_default_template_html( 'product_replace' ),
				'content_text' => $this->get_default_template_text( 'product_replace' ),
				'variables' => wp_json_encode( ['post_title', 'post_url', 'new_product', 'site_name', 'site_url', 'unsubscribe_url'] ),
				'is_default' => 1,
				'is_active' => 1,
			],
			[
				'template_name' => __( 'New Post Notification', 'aebg' ),
				'template_type' => 'new_post',
				'subject_template' => __( 'New Post: {post_title}', 'aebg' ),
				'content_html' => $this->get_default_template_html( 'new_post' ),
				'content_text' => $this->get_default_template_text( 'new_post' ),
				'variables' => wp_json_encode( ['post_title', 'post_url', 'post_excerpt', 'site_name', 'site_url', 'unsubscribe_url'] ),
				'is_default' => 1,
				'is_active' => 1,
			],
		];
		
		foreach ( $templates as $template_data ) {
			$wpdb->insert(
				$table,
				[
					'template_name' => $template_data['template_name'],
					'template_type' => $template_data['template_type'],
					'subject_template' => $template_data['subject_template'],
					'content_html' => $template_data['content_html'],
					'content_text' => $template_data['content_text'],
					'variables' => $template_data['variables'],
					'is_default' => $template_data['is_default'],
					'is_active' => $template_data['is_active'],
					'user_id' => 1,
				],
				['%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d']
			);
		}
		
		error_log( '[AEBG] Created default email templates' );
	}
	
	/**
	 * Get default HTML template content
	 * 
	 * @param string $type Template type
	 * @return string HTML content
	 */
	private function get_default_template_html( $type ) {
		$templates = [
			'post_update' => '<!DOCTYPE html>
<html>
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>{post_title}</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
	<div style="background: #f8f9fa; padding: 20px; border-radius: 8px; margin-bottom: 20px;">
		<h1 style="margin: 0 0 10px 0; color: #667eea;">{post_title}</h1>
		<p style="margin: 0; color: #666;">{site_name}</p>
	</div>
	
	<div style="background: #fff; padding: 20px; border-radius: 8px; border: 1px solid #e0e0e0;">
		<p>Hello,</p>
		<p>We\'ve updated our post and thought you might be interested:</p>
		<p style="font-size: 16px; line-height: 1.8;">{post_excerpt}</p>
		<p style="margin: 30px 0;">
			<a href="{post_url}" style="display: inline-block; background: #667eea; color: #fff; padding: 12px 24px; text-decoration: none; border-radius: 6px; font-weight: bold;">Read More</a>
		</p>
	</div>
	
	<div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #e0e0e0; text-align: center; font-size: 12px; color: #999;">
		<p>You received this email because you subscribed to updates from {site_name}.</p>
		<p><a href="{unsubscribe_url}" style="color: #999;">Unsubscribe</a></p>
	</div>
</body>
</html>',
			'product_reorder' => '<!DOCTYPE html>
<html>
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Products Reordered: {post_title}</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
	<div style="background: #f8f9fa; padding: 20px; border-radius: 8px; margin-bottom: 20px;">
		<h1 style="margin: 0 0 10px 0; color: #667eea;">Products Reordered</h1>
		<p style="margin: 0; color: #666;">{site_name}</p>
	</div>
	
	<div style="background: #fff; padding: 20px; border-radius: 8px; border: 1px solid #e0e0e0;">
		<p>Hello,</p>
		<p>We\'ve reordered the products in our post:</p>
		<h2 style="color: #667eea; margin-top: 20px;">{post_title}</h2>
		<div style="margin: 20px 0;">
			{product_list}
		</div>
		<p style="margin: 30px 0;">
			<a href="{post_url}" style="display: inline-block; background: #667eea; color: #fff; padding: 12px 24px; text-decoration: none; border-radius: 6px; font-weight: bold;">View Post</a>
		</p>
	</div>
	
	<div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #e0e0e0; text-align: center; font-size: 12px; color: #999;">
		<p>You received this email because you subscribed to updates from {site_name}.</p>
		<p><a href="{unsubscribe_url}" style="color: #999;">Unsubscribe</a></p>
	</div>
</body>
</html>',
			'product_replace' => '<!DOCTYPE html>
<html>
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>New Product: {post_title}</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
	<div style="background: #f8f9fa; padding: 20px; border-radius: 8px; margin-bottom: 20px;">
		<h1 style="margin: 0 0 10px 0; color: #667eea;">New Product Added</h1>
		<p style="margin: 0; color: #666;">{site_name}</p>
	</div>
	
	<div style="background: #fff; padding: 20px; border-radius: 8px; border: 1px solid #e0e0e0;">
		<p>Hello,</p>
		<p>We\'ve added a new product to our post:</p>
		<h2 style="color: #667eea; margin-top: 20px;">{post_title}</h2>
		<div style="margin: 20px 0; padding: 15px; background: #f8f9fa; border-radius: 6px;">
			{new_product}
		</div>
		<p style="margin: 30px 0;">
			<a href="{post_url}" style="display: inline-block; background: #667eea; color: #fff; padding: 12px 24px; text-decoration: none; border-radius: 6px; font-weight: bold;">View Post</a>
		</p>
	</div>
	
	<div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #e0e0e0; text-align: center; font-size: 12px; color: #999;">
		<p>You received this email because you subscribed to updates from {site_name}.</p>
		<p><a href="{unsubscribe_url}" style="color: #999;">Unsubscribe</a></p>
	</div>
</body>
</html>',
			'new_post' => '<!DOCTYPE html>
<html>
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>New Post: {post_title}</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
	<div style="background: #f8f9fa; padding: 20px; border-radius: 8px; margin-bottom: 20px;">
		<h1 style="margin: 0 0 10px 0; color: #667eea;">New Post Published</h1>
		<p style="margin: 0; color: #666;">{site_name}</p>
	</div>
	
	<div style="background: #fff; padding: 20px; border-radius: 8px; border: 1px solid #e0e0e0;">
		<p>Hello,</p>
		<p>We\'ve just published a new post:</p>
		<h2 style="color: #667eea; margin-top: 20px;">{post_title}</h2>
		<p style="font-size: 16px; line-height: 1.8;">{post_excerpt}</p>
		<p style="margin: 30px 0;">
			<a href="{post_url}" style="display: inline-block; background: #667eea; color: #fff; padding: 12px 24px; text-decoration: none; border-radius: 6px; font-weight: bold;">Read Post</a>
		</p>
	</div>
	
	<div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #e0e0e0; text-align: center; font-size: 12px; color: #999;">
		<p>You received this email because you subscribed to updates from {site_name}.</p>
		<p><a href="{unsubscribe_url}" style="color: #999;">Unsubscribe</a></p>
	</div>
</body>
</html>',
		];
		
		return $templates[ $type ] ?? '';
	}
	
	/**
	 * Get default plain text template content
	 * 
	 * @param string $type Template type
	 * @return string Plain text content
	 */
	private function get_default_template_text( $type ) {
		$templates = [
			'post_update' => "Update: {post_title}\n\nHello,\n\nWe've updated our post and thought you might be interested:\n\n{post_excerpt}\n\nRead more: {post_url}\n\n---\nYou received this email because you subscribed to updates from {site_name}.\nUnsubscribe: {unsubscribe_url}",
			'product_reorder' => "Products Reordered: {post_title}\n\nHello,\n\nWe've reordered the products in our post:\n\n{post_title}\n\n{product_list}\n\nView post: {post_url}\n\n---\nYou received this email because you subscribed to updates from {site_name}.\nUnsubscribe: {unsubscribe_url}",
			'product_replace' => "New Product Added: {post_title}\n\nHello,\n\nWe've added a new product to our post:\n\n{post_title}\n\n{new_product}\n\nView post: {post_url}\n\n---\nYou received this email because you subscribed to updates from {site_name}.\nUnsubscribe: {unsubscribe_url}",
			'new_post' => "New Post: {post_title}\n\nHello,\n\nWe've just published a new post:\n\n{post_title}\n\n{post_excerpt}\n\nRead post: {post_url}\n\n---\nYou received this email because you subscribed to updates from {site_name}.\nUnsubscribe: {unsubscribe_url}",
		];
		
		return $templates[ $type ] ?? '';
	}
	
	/**
	 * Deactivate the plugin.
	 */
	public function deactivate() {
		// Remove custom capability
		$editor_role = get_role( 'editor' );
		$editor_role->remove_cap( 'aebg_generate_content' );
		$admin_role = get_role( 'administrator' );
		$admin_role->remove_cap( 'aebg_generate_content' );
		remove_role( 'aebg_generator' );
	}
}
