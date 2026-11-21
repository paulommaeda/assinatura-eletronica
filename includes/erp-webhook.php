<?php
namespace EduSign;
defined('ABSPATH') || exit;

final class ErpWebhook {
    public static function configuration(array $input,array $current): array {
        $url = $input['erp_webhook_url'] ?? $current['erp_webhook_url'];
        $replacement = $input['erp_webhook_secret'] ?? '';
        Store::require(is_string($url) && is_string($replacement),'Configuração do retorno ao ERP inválida.');
        $url = trim($url);
        if ($url !== '') {
            $parts = wp_parse_url($url);
            Store::require(filter_var($url,FILTER_VALIDATE_URL) !== false && ($parts['scheme'] ?? '') === 'https'
                && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['fragment']),
                'Informe a URL HTTPS do ERP sem credenciais ou fragmento.');
        }
        $secret = $replacement !== '' ? $replacement : $current['erp_webhook_secret'];
        if ($url !== '' || $replacement !== '') {
            Store::require(is_string($secret) && preg_match('/^[!-~]{32,256}$/D',$secret) === 1,
                'Use um segredo compartilhado de 32 a 256 caracteres ASCII sem espaços.');
        }
        return ['erp_webhook_url'=>$url,'erp_webhook_secret'=>$secret];
    }

    public static function queue(object $contract): void {
        if (Contracts::isErpPdf($contract) && Store::settings()['erp_webhook_url'] !== '') {
            Delivery::queue((int)$contract->id,'erp_webhook');
        }
    }

    public static function deliver(object $contract): bool {
        $settings = Store::settings();
        $status = $contract->status;
        if (!$settings['erp_webhook_url'] || !$settings['erp_webhook_secret'] || !Contracts::isErpPdf($contract)
            || !in_array($status,['signed','cancelled','expired'],true)) {
            return false;
        }
        $body = Store::json([
            'event_id'=>$contract->uuid.':'.$status, 'event'=>'contract.'.$status,
            'uuid'=>$contract->uuid, 'external_ref'=>$contract->external_ref,
            'status'=>$status, 'is_signed'=>$status === 'signed', 'signed_at'=>$contract->signed_at,
            'issued_sha256'=>$contract->issued_hash, 'pdf_sha256'=>$contract->pdf_hash_sha256,
        ]);
        $timestamp = (string) time();
        $signature = hash_hmac('sha256',$timestamp.'.'.$body,$settings['erp_webhook_secret']);
        $response = wp_safe_remote_post($settings['erp_webhook_url'],[
            'timeout'=>8, 'redirection'=>0, 'limit_response_size'=>1024,
            'headers'=>['Content-Type'=>'application/json','X-Edu-Sign-Timestamp'=>$timestamp,
                'X-Edu-Sign-Signature'=>'sha256='.$signature],
            'body'=>$body,
        ]);
        $code = wp_remote_retrieve_response_code($response);
        return !is_wp_error($response) && $code >= 200 && $code < 300;
    }
}
