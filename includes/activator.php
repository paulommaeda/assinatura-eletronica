<?php
namespace EduSign;
defined('ABSPATH') || exit;

final class Activator {
    const SCHEMA = '1';
    public static function schedules(array $schedules): array {
        $schedules['edu_sign_every_five_minutes'] = ['interval' => 300, 'display' => 'Contratos · 5 minutos'];
        return $schedules;
    }
    public static function activate($network_wide = false): void {
        if ($network_wide) { wp_die('Ative o plugin individualmente em cada site da rede.'); }
        foreach (['mbstring','dom','gd'] as $ext) {
            if (!extension_loaded($ext)) { wp_die(esc_html("Ative a extensão PHP $ext para gerar os PDFs.")); }
        }
        if (!is_readable(DIR . '/vendor/autoload.php')) { wp_die('Instale o ZIP distribuído na página do projeto.'); }
        self::install();
    }
    public static function upgrade(): void {
        if (get_option('edu_sign_schema') !== self::SCHEMA) { self::install(); }
    }
    private static function install(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $tables = [
            'templates' => "id bigint unsigned NOT NULL AUTO_INCREMENT,\n family char(36) NOT NULL,\n title varchar(180) NOT NULL,\n version int unsigned NOT NULL,\n html_content longtext NOT NULL,\n required_clauses_json text NOT NULL,\n status varchar(16) NOT NULL,\n created_at datetime NOT NULL,\n PRIMARY KEY  (id),\n UNIQUE KEY family_version (family,version),\n KEY status (status)",
            'contracts' => "id bigint unsigned NOT NULL AUTO_INCREMENT,\n uuid char(36) NOT NULL,\n external_ref varchar(160) DEFAULT NULL,\n request_hash char(64) NOT NULL,\n template_id bigint unsigned NOT NULL,\n title varchar(180) NOT NULL,\n template_version int unsigned NOT NULL,\n student_ref varchar(100) NOT NULL,\n student_name varchar(180) NOT NULL,\n guardian_name varchar(180) NOT NULL,\n guardian_cpf_hash char(64) NOT NULL,\n cpf_suffix char(2) NOT NULL,\n guardian_email varchar(190) NOT NULL,\n guardian_phone varchar(32) NOT NULL,\n institution varchar(180) NOT NULL,\n rendered_html longtext NOT NULL,\n clauses_json text NOT NULL,\n issued_hash char(64) NOT NULL,\n pdf_hash_sha256 char(64) DEFAULT NULL,\n invite_seed char(64) NOT NULL,\n status varchar(16) NOT NULL,\n created_at datetime NOT NULL,\n expires_at datetime NOT NULL,\n signed_at datetime DEFAULT NULL,\n access_until datetime DEFAULT NULL,\n archived_at datetime DEFAULT NULL,\n audit_head char(64) NOT NULL DEFAULT '',\n event_count int unsigned NOT NULL DEFAULT 0,\n PRIMARY KEY  (id),\n UNIQUE KEY uuid (uuid),\n UNIQUE KEY external_ref (external_ref),\n KEY status_expires (status,expires_at),\n KEY student_ref (student_ref)",
            'documents' => "id bigint unsigned NOT NULL AUTO_INCREMENT,\n contract_id bigint unsigned NOT NULL,\n kind varchar(12) NOT NULL,\n pdf longblob NOT NULL,\n PRIMARY KEY  (id),\n UNIQUE KEY document (contract_id,kind)",
            'sessions' => "id bigint unsigned NOT NULL AUTO_INCREMENT,\n contract_id bigint unsigned NOT NULL,\n token_hash char(64) NOT NULL,\n created_at datetime NOT NULL,\n expires_at datetime NOT NULL,\n viewed_at datetime DEFAULT NULL,\n PRIMARY KEY  (id),\n UNIQUE KEY token_hash (token_hash),\n KEY contract_id (contract_id),\n KEY expires_at (expires_at)",
            'acknowledgments' => "id bigint unsigned NOT NULL AUTO_INCREMENT,\n contract_id bigint unsigned NOT NULL,\n session_id bigint unsigned NOT NULL,\n clause_key varchar(64) NOT NULL,\n label text NOT NULL,\n acknowledged_at datetime NOT NULL,\n scroll_percent tinyint unsigned NOT NULL,\n time_spent_seconds int unsigned NOT NULL,\n PRIMARY KEY  (id),\n UNIQUE KEY acknowledged (contract_id,session_id,clause_key)",
            'otps' => "id bigint unsigned NOT NULL AUTO_INCREMENT,\n contract_id bigint unsigned NOT NULL,\n session_id bigint unsigned NOT NULL,\n channel varchar(12) NOT NULL,\n token_hash char(64) NOT NULL,\n created_at datetime NOT NULL,\n expires_at datetime NOT NULL,\n attempts smallint unsigned NOT NULL DEFAULT 0,\n state varchar(16) NOT NULL,\n verified_at datetime DEFAULT NULL,\n PRIMARY KEY  (id),\n KEY contract_created (contract_id,created_at),\n KEY expires_at (expires_at)",
            'audit_logs' => "id bigint unsigned NOT NULL AUTO_INCREMENT,\n contract_id bigint unsigned NOT NULL,\n seq int unsigned NOT NULL,\n event_type varchar(64) NOT NULL,\n actor_type varchar(20) NOT NULL,\n ip_hash char(64) NOT NULL,\n agent_hash char(64) NOT NULL,\n payload_json longtext NOT NULL,\n prev_hash char(64) NOT NULL,\n event_hash char(64) NOT NULL,\n created_at datetime NOT NULL,\n PRIMARY KEY  (id),\n UNIQUE KEY sequence (contract_id,seq)",
            'jobs' => "id bigint unsigned NOT NULL AUTO_INCREMENT,\n contract_id bigint unsigned NOT NULL,\n kind varchar(16) NOT NULL,\n state varchar(12) NOT NULL,\n attempts int unsigned NOT NULL DEFAULT 0,\n due_at datetime NOT NULL,\n lease char(64) NOT NULL DEFAULT '',\n PRIMARY KEY  (id),\n KEY due (state,due_at),\n KEY contract_id (contract_id)",
            'limits' => "bucket char(64) NOT NULL,\n hits int unsigned NOT NULL,\n expires_at datetime NOT NULL,\n PRIMARY KEY  (bucket),\n KEY expires_at (expires_at)",
        ];
        foreach ($tables as $suffix => $columns) {
            $name = Store::t($suffix);
            dbDelta("CREATE TABLE $name (\n $columns\n) ENGINE=InnoDB " . $wpdb->get_charset_collate() . ';');
            $engine = $wpdb->get_var($wpdb->prepare("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s", $name));
            if (strcasecmp((string)$engine, 'InnoDB') !== 0) { throw new \RuntimeException('Os contratos requerem tabelas InnoDB.'); }
        }
        add_role('edu_contract_manager', 'Gestor de contratos', ['read' => true, CAP => true]);
        foreach (['administrator','edu_contract_manager'] as $role) {
            if ($wp_role = get_role($role)) { $wp_role->add_cap(CAP); }
        }
        if (!wp_next_scheduled('edu_sign_worker')) { wp_schedule_event(time()+60, 'edu_sign_every_five_minutes', 'edu_sign_worker'); }
        if (!wp_next_scheduled('edu_sign_maintenance')) { wp_schedule_event(time()+300, 'hourly', 'edu_sign_maintenance'); }
        update_option('edu_sign_schema', self::SCHEMA, false);
    }
    public static function deactivate(): void {
        wp_clear_scheduled_hook('edu_sign_worker');
        wp_clear_scheduled_hook('edu_sign_maintenance');
    }
}
