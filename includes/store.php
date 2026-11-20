<?php
namespace EduSign;
defined('ABSPATH') || exit;

final class Problem extends \RuntimeException {}

final class Store {
    public static function t(string $suffix): string {
        global $wpdb;
        return $wpdb->prefix . 'edu_contract' . ($suffix === 'contracts' ? 's' : '_' . $suffix);
    }

    public static function now(): string { return gmdate('Y-m-d H:i:s'); }
    public static function after(int $seconds): string { return gmdate('Y-m-d H:i:s', time() + $seconds); }
    public static function json($value): string {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
    public static function mac(string $scope, ...$values): string {
        $key = defined('EDU_SIGN_KEY') ? EDU_SIGN_KEY : wp_salt('auth');
        return hash_hmac('sha256', self::json([$scope, ...$values]), $key);
    }
    public static function token(): string { return bin2hex(random_bytes(32)); }
    public static function require(bool $ok, string $message, int $code = 422): void {
        if (!$ok) { throw new Problem($message, $code); }
    }
    public static function checked($value) {
        if ($value === false) { throw new \RuntimeException('Falha de gravação no banco de dados.'); }
        return $value;
    }
    public static function insert(string $table, array $data): int {
        global $wpdb;
        self::checked($wpdb->insert(self::t($table), $data));
        return (int)$wpdb->insert_id;
    }
    public static function update(string $table, array $data, array $where): void {
        global $wpdb;
        self::checked($wpdb->update(self::t($table), $data, $where));
    }
    public static function tx(callable $work) {
        global $wpdb;
        self::checked($wpdb->query('START TRANSACTION'));
        try {
            $result = $work();
            self::checked($wpdb->query('COMMIT'));
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        }
        // Business failures which must keep a consumed attempt are returned after commit.
        if (is_wp_error($result)) { throw new Problem($result->get_error_message(), (int)$result->get_error_data()); }
        return $result;
    }
    public static function contract(string $uuid, bool $lock = false): object {
        global $wpdb;
        $sql = 'SELECT * FROM ' . self::t('contracts') . ' WHERE uuid=%s' . ($lock ? ' FOR UPDATE' : '');
        $row = $wpdb->get_row($wpdb->prepare($sql, $uuid));
        self::require((bool)$row, 'Contrato indisponível.', 404);
        return $row;
    }
    public static function https(): void {
        self::require(is_ssl(), 'O portal de contratos requer HTTPS.', 403);
    }
    public static function text($value, string $label, int $max = 180, bool $required = true): string {
        self::require(is_scalar($value), "Valor inválido em $label.");
        $text = trim(sanitize_text_field((string)$value));
        self::require((!$required || $text !== '') && mb_strlen($text) <= $max, "Verifique o campo $label.");
        return $text;
    }
    public static function cpf($value): string {
        self::require(is_string($value) && preg_match('/^[\d.\s-]{11,18}$/D', $value) === 1, 'CPF inválido.');
        $cpf = preg_replace('/\D/', '', $value);
        self::require(strlen($cpf) === 11 && !preg_match('/^(\d)\1{10}$/D', $cpf), 'CPF inválido.');
        for ($pos = 9; $pos <= 10; $pos++) {
            $sum = 0;
            for ($i = 0; $i < $pos; $i++) { $sum += (int)$cpf[$i] * ($pos + 1 - $i); }
            self::require((($sum * 10) % 11) % 10 === (int)$cpf[$pos], 'CPF inválido.');
        }
        return $cpf;
    }
    public static function birthDate($value): string {
        $message = 'Informe uma data de nascimento válida para o responsável (DD/MM/AAAA ou AAAA-MM-DD).';
        self::require(is_string($value), $message);
        $date = trim($value);
        if (preg_match('~^([0-9]{2})/([0-9]{2})/([0-9]{4})$~D', $date, $parts)) {
            $date = $parts[3].'-'.$parts[2].'-'.$parts[1];
        }
        self::require(preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D', $date, $parts) === 1
            && checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1])
            && $date <= wp_date('Y-m-d'), $message);
        return $date;
    }
    public static function settings(): array {
        return wp_parse_args((array)get_option('edu_sign_settings', []), [
            'institution' => wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES),
            'valid_days' => 30, 'retention_days' => 0, 'record_connection' => false,
        ]);
    }
    public static function agent(): string {
        $agent = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 512);
        return $agent === '' ? '' : self::mac('user-agent', $agent);
    }
    public static function ip(): string {
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        return $ip === '' ? '' : self::mac('ip', $ip);
    }
    public static function connectionDetails(): array {
        $address = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $agent = sanitize_text_field(wp_unslash((string)($_SERVER['HTTP_USER_AGENT'] ?? '')));
        return [
            'ip' => filter_var($address, FILTER_VALIDATE_IP) ? $address : '',
            'user_agent' => mb_strcut($agent, 0, 512, 'UTF-8'),
        ];
    }
}
