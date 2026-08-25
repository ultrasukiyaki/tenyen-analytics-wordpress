<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class TYA_Installer
{
    public const SCHEMA_VERSION = '0.7.1';
    public static function tableName(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'tya_events';
    }

    public static function activate(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = self::tableName();
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            event_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            occurred_at DATETIME NOT NULL,
            event_type VARCHAR(32) NOT NULL,
            visitor_id VARCHAR(64) NOT NULL DEFAULT '',
            session_id VARCHAR(64) NOT NULL DEFAULT '',
            ip_encrypted VARBINARY(255) NULL,
            ip_hash BINARY(32) NULL,
            ip_version TINYINT UNSIGNED NOT NULL DEFAULT 0,
            country_code CHAR(2) NOT NULL DEFAULT '',
            country_name VARCHAR(128) NOT NULL DEFAULT '',
            region VARCHAR(128) NOT NULL DEFAULT '',
            city VARCHAR(128) NOT NULL DEFAULT '',
            latitude DECIMAL(10,7) NULL,
            longitude DECIMAL(10,7) NULL,
            accuracy_radius SMALLINT UNSIGNED NULL,
            asn INT UNSIGNED NULL,
            asn_org VARCHAR(255) NOT NULL DEFAULT '',
            path TEXT NOT NULL,
            page_title VARCHAR(512) NOT NULL DEFAULT '',
            referrer TEXT NULL,
            target_url TEXT NULL,
            target_host VARCHAR(255) NOT NULL DEFAULT '',
            event_name VARCHAR(64) NOT NULL DEFAULT '',
            event_meta TEXT NULL,
            traffic_channel VARCHAR(32) NOT NULL DEFAULT '',
            referrer_host VARCHAR(255) NOT NULL DEFAULT '',
            utm_source VARCHAR(128) NOT NULL DEFAULT '',
            utm_medium VARCHAR(128) NOT NULL DEFAULT '',
            utm_campaign VARCHAR(256) NOT NULL DEFAULT '',
            utm_content VARCHAR(256) NOT NULL DEFAULT '',
            utm_term VARCHAR(256) NOT NULL DEFAULT '',
            user_agent VARCHAR(1024) NOT NULL DEFAULT '',
            browser VARCHAR(64) NOT NULL DEFAULT '',
            os VARCHAR(64) NOT NULL DEFAULT '',
            device_type VARCHAR(32) NOT NULL DEFAULT '',
            language VARCHAR(32) NOT NULL DEFAULT '',
            timezone VARCHAR(64) NOT NULL DEFAULT '',
            screen VARCHAR(32) NOT NULL DEFAULT '',
            viewport VARCHAR(32) NOT NULL DEFAULT '',
            duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
            scroll_depth TINYINT UNSIGNED NOT NULL DEFAULT 0,
            is_bot TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY  (event_id),
            KEY occurred_event (occurred_at, event_type),
            KEY visitor_time (visitor_id, occurred_at),
            KEY session_time (session_id, occurred_at),
            KEY ip_time (ip_hash, occurred_at),
            KEY asn_time (asn, occurred_at),
            KEY country_time (country_code, occurred_at),
            KEY bot_time (is_bot, occurred_at),
            KEY channel_time (traffic_channel, occurred_at),
            KEY campaign_time (utm_source, utm_medium, occurred_at),
            KEY event_name_time (event_name, occurred_at)
        ) {$charset};";

        dbDelta($sql);

        $annotations = self::annotationsTable();
        $tags = self::tagsTable();
        $relations = self::entityTagsTable();
        $views = self::savedViewsTable();
        $exclusions = self::exclusionsTable();
        $daily = self::dailyAggregatesTable();
        $dimensions = self::dailyDimensionsTable();
        dbDelta("CREATE TABLE {$annotations} (
            annotation_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            entity_type VARCHAR(32) NOT NULL,
            entity_key VARBINARY(191) NOT NULL,
            original_value VARCHAR(512) NOT NULL DEFAULT '',
            context_json TEXT NULL,
            alias VARCHAR(120) NOT NULL DEFAULT '',
            note TEXT NULL,
            watched TINYINT(1) NOT NULL DEFAULT 0,
            created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
            updated_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (annotation_id),
            UNIQUE KEY entity_identity (entity_type,entity_key),
            KEY watched_type (watched,entity_type),
            KEY updated_at (updated_at)
        ) {$charset};");
        dbDelta("CREATE TABLE {$tags} (
            tag_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(50) NOT NULL,
            normalized_name VARCHAR(100) NOT NULL,
            color VARCHAR(16) NOT NULL DEFAULT 'blue',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (tag_id),
            UNIQUE KEY normalized_name (normalized_name)
        ) {$charset};");
        dbDelta("CREATE TABLE {$relations} (
            annotation_id BIGINT UNSIGNED NOT NULL,
            tag_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (annotation_id,tag_id),
            KEY tag_annotation (tag_id,annotation_id)
        ) {$charset};");
        dbDelta("CREATE TABLE {$views} (
            view_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            report VARCHAR(32) NOT NULL,
            name VARCHAR(120) NOT NULL,
            description VARCHAR(500) NOT NULL DEFAULT '',
            schema_version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            filters_json TEXT NOT NULL,
            pinned TINYINT(1) NOT NULL DEFAULT 0,
            is_default TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (view_id),
            KEY owner_report (user_id,report),
            KEY owner_pinned (user_id,pinned,is_default)
        ) {$charset};");
        dbDelta("CREATE TABLE {$exclusions} (
            rule_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            rule_type VARCHAR(32) NOT NULL,
            rule_value VARCHAR(512) NOT NULL,
            scope VARCHAR(16) NOT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            note VARCHAR(500) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (rule_id),
            KEY active_scope (enabled,scope,rule_type)
        ) {$charset};");
        dbDelta("CREATE TABLE {$daily} (
            aggregate_day DATE NOT NULL,
            actor VARCHAR(8) NOT NULL,
            pageviews BIGINT UNSIGNED NOT NULL DEFAULT 0,
            events BIGINT UNSIGNED NOT NULL DEFAULT 0,
            visitors BIGINT UNSIGNED NOT NULL DEFAULT 0,
            sessions BIGINT UNSIGNED NOT NULL DEFAULT 0,
            bounces BIGINT UNSIGNED NOT NULL DEFAULT 0,
            entries BIGINT UNSIGNED NOT NULL DEFAULT 0,
            exits BIGINT UNSIGNED NOT NULL DEFAULT 0,
            engaged_ms BIGINT UNSIGNED NOT NULL DEFAULT 0,
            engagement_samples BIGINT UNSIGNED NOT NULL DEFAULT 0,
            scroll_sum BIGINT UNSIGNED NOT NULL DEFAULT 0,
            scroll_samples BIGINT UNSIGNED NOT NULL DEFAULT 0,
            bot_events BIGINT UNSIGNED NOT NULL DEFAULT 0,
            visitor_sketch MEDIUMBLOB NULL,
            session_sketch MEDIUMBLOB NULL,
            source_events BIGINT UNSIGNED NOT NULL DEFAULT 0,
            source_max_event_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            rule_signature CHAR(64) NOT NULL,
            generated_at DATETIME NOT NULL,
            PRIMARY KEY  (aggregate_day,actor),
            KEY actor_signature_day (actor,rule_signature,aggregate_day)
        ) {$charset};");
        dbDelta("CREATE TABLE {$dimensions} (
            aggregate_day DATE NOT NULL,
            actor VARCHAR(8) NOT NULL,
            dimension_type VARCHAR(24) NOT NULL,
            dimension_hash BINARY(32) NOT NULL,
            dimension_key VARCHAR(512) NOT NULL DEFAULT '',
            dimension_label VARCHAR(512) NOT NULL DEFAULT '',
            pageviews BIGINT UNSIGNED NOT NULL DEFAULT 0,
            events BIGINT UNSIGNED NOT NULL DEFAULT 0,
            visitors BIGINT UNSIGNED NOT NULL DEFAULT 0,
            sessions BIGINT UNSIGNED NOT NULL DEFAULT 0,
            bounces BIGINT UNSIGNED NOT NULL DEFAULT 0,
            entries BIGINT UNSIGNED NOT NULL DEFAULT 0,
            exits BIGINT UNSIGNED NOT NULL DEFAULT 0,
            engaged_ms BIGINT UNSIGNED NOT NULL DEFAULT 0,
            engagement_samples BIGINT UNSIGNED NOT NULL DEFAULT 0,
            scroll_sum BIGINT UNSIGNED NOT NULL DEFAULT 0,
            scroll_samples BIGINT UNSIGNED NOT NULL DEFAULT 0,
            last_seen DATETIME NULL,
            rule_signature CHAR(64) NOT NULL,
            generated_at DATETIME NOT NULL,
            PRIMARY KEY  (aggregate_day,actor,dimension_type,dimension_hash),
            KEY type_actor_day (dimension_type,actor,aggregate_day),
            KEY dimension_lookup (dimension_type,dimension_hash)
        ) {$charset};");
        update_option('tya_schema_version', self::SCHEMA_VERSION, false);

        if (!get_option('tya_site_token')) {
            add_option('tya_site_token', wp_generate_password(32, false, false), '', false);
        }
        add_option('tya_retention_days', 90, '', false);
        add_option('tya_exclude_admins', 1, '', false);
        add_option('tya_proxy_header', '', '', false);
        add_option('tya_log_bots', 1, '', false);
        add_option('tya_org_overrides', '', '', false);
        add_option('tya_track_internal_links', 0, '', false);
        add_option('tya_track_buttons', 0, '', false);
        add_option('tya_track_forms', 0, '', false);

        $upload = wp_upload_dir();
        $geoDir = trailingslashit($upload['basedir']) . 'tenyen-analytics';
        if (!is_dir($geoDir)) {
            wp_mkdir_p($geoDir);
        }
        add_option('tya_city_db', $geoDir . '/GeoLite2-City.mmdb', '', false);
        add_option('tya_asn_db', $geoDir . '/GeoLite2-ASN.mmdb', '', false);
        add_option('tya_maxmind_account_id', '', '', false);
        add_option('tya_maxmind_license_key', '', '', false);
        add_option('tya_geolite_auto_update', 0, '', false);
        add_option('tya_geolite_state', [], '', false);

        if (!wp_next_scheduled('tya_daily_cleanup')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'tya_daily_cleanup');
        }
        if (!wp_next_scheduled('tya_daily_aggregation')) {
            wp_schedule_event(time() + 2 * HOUR_IN_SECONDS, 'daily', 'tya_daily_aggregation');
        }
    }

    public static function annotationsTable(): string { global $wpdb; return $wpdb->prefix . 'tya_annotations'; }
    public static function tagsTable(): string { global $wpdb; return $wpdb->prefix . 'tya_tags'; }
    public static function entityTagsTable(): string { global $wpdb; return $wpdb->prefix . 'tya_entity_tags'; }
    public static function savedViewsTable(): string { global $wpdb; return $wpdb->prefix . 'tya_saved_views'; }
    public static function exclusionsTable(): string { global $wpdb; return $wpdb->prefix . 'tya_exclusion_rules'; }
    public static function dailyAggregatesTable(): string { global $wpdb; return $wpdb->prefix . 'tya_daily_aggregates'; }
    public static function dailyDimensionsTable(): string { global $wpdb; return $wpdb->prefix . 'tya_daily_dimensions'; }

    public static function maybeUpgrade(): void
    {
        if ((string)get_option('tya_schema_version', '') !== self::SCHEMA_VERSION) {
            self::activate();
        }
    }

    public static function deactivate(): void
    {
        $timestamp = wp_next_scheduled('tya_daily_cleanup');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'tya_daily_cleanup');
        }
        $continuation = wp_next_scheduled('tya_cleanup_continue');
        if ($continuation) wp_unschedule_event($continuation, 'tya_cleanup_continue');
        foreach (['tya_daily_aggregation', 'tya_aggregation_continue'] as $hook) {
            $timestamp = wp_next_scheduled($hook);
            if ($timestamp) wp_unschedule_event($timestamp, $hook);
        }
        foreach (['tya_geolite_weekly_update', 'tya_geolite_retry'] as $hook) {
            $timestamp = wp_next_scheduled($hook);
            if ($timestamp) wp_unschedule_event($timestamp, $hook);
        }
    }
}
