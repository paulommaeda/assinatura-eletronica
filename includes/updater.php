<?php
namespace EduSign;
defined('ABSPATH') || exit;

final class Updater {
    const SLUG = 'assinatura-eletronica';
    const RAW = 'https://raw.githubusercontent.com/paulommaeda/assinatura-eletronica/main/';
    const CACHE = 'edu_sign_github_update_v1';

    public static function register(): void {
        add_filter('update_plugins_github.com',[self::class,'check'],10,3);
        add_filter('plugins_api',[self::class,'info'],10,3);
        add_filter('upgrader_pre_download',[self::class,'download'],10,4);
        add_action('delete_site_transient_update_plugins',static function () { delete_site_transient(self::CACHE); });
    }
    private static function metadata(): ?array {
        $cached = get_site_transient(self::CACHE);
        if (is_array($cached)) { return $cached ?: null; }
        $res = wp_safe_remote_get(self::RAW.'update.json',['timeout'=>8,'limit_response_size'=>16000]);
        $m = !is_wp_error($res) && wp_remote_retrieve_response_code($res) === 200 ? json_decode(wp_remote_retrieve_body($res),true) : [];
        if (!is_array($m) || !preg_match('/^\d+\.\d+\.\d+$/D',(string)($m['version'] ?? ''))
            || !preg_match('/^[0-9a-f]{64}$/D',(string)($m['sha256'] ?? ''))) { $m = []; }
        if ($m) { $m['package'] = self::RAW.'dist/'.self::SLUG.'-'.$m['version'].'.zip'; }
        set_site_transient(self::CACHE,$m,$m ? 4*HOUR_IN_SECONDS : 5*MINUTE_IN_SECONDS);
        return $m ?: null;
    }
    public static function check($update,$plugin,$file) {
        if ($file !== plugin_basename(FILE) || ($plugin['UpdateURI'] ?? '') !== REPO) { return $update; }
        $m = self::metadata();
        if (!$m) { return $update; }
        return ['id'=>REPO,'slug'=>self::SLUG,'version'=>$m['version'],'url'=>REPO,'package'=>$m['package'],
            'requires'=>$m['requires'] ?? '6.3','requires_php'=>$m['requires_php'] ?? '8.1','tested'=>$m['tested'] ?? ''];
    }
    public static function info($result,$action,$args) {
        if ($action !== 'plugin_information' || ($args->slug ?? '') !== self::SLUG) { return $result; }
        $m = self::metadata();
        if (!$m) { return $result; }
        return (object)['name'=>'Assinatura Eletrônica Educacional','slug'=>self::SLUG,'version'=>$m['version'],'homepage'=>REPO,
            'download_link'=>$m['package'],'requires'=>$m['requires'] ?? '6.3','requires_php'=>$m['requires_php'] ?? '8.1','tested'=>$m['tested'] ?? '',
            'sections'=>['description'=>'Emissão e assinatura de contratos educacionais.','changelog'=>esc_html($m['notes'] ?? '')]];
    }
    public static function download($reply,$package,$upgrader,$extra) {
        if (($extra['plugin'] ?? '') !== plugin_basename(FILE) || is_wp_error($reply)) { return $reply; }
        $m = self::metadata();
        if (!$m || !hash_equals($m['package'],(string)$package)) { return new \WP_Error('edu_update','O pacote de atualização não confere com a versão publicada. Verifique as atualizações novamente.'); }
        require_once ABSPATH.'wp-admin/includes/file.php';
        $tmp = $reply === false ? download_url($package,120) : $reply;
        if (is_wp_error($tmp)) { return $tmp; }
        $hash = is_string($tmp) && is_file($tmp) && is_readable($tmp) ? hash_file('sha256',$tmp) : false;
        if (!is_string($hash) || !hash_equals($m['sha256'],$hash)) {
            if ($reply === false && is_string($tmp)) { wp_delete_file($tmp); }
            return new \WP_Error('edu_hash','O SHA-256 do ZIP diverge do manifesto. A instalação foi interrompida.');
        }
        return $tmp;
    }
}
