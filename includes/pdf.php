<?php
namespace EduSign;
defined('ABSPATH') || exit;

use Dompdf\Dompdf;
use Dompdf\Options;
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\StreamReader;

final class Pdf {
    public static function decodeOriginal(array $document): string {
        $encoded = $document['pdf_base64'] ?? null;
        $max = 8 * MB_IN_BYTES;
        Store::require(is_string($encoded) && $encoded !== '', 'Informe o PDF em document.pdf_base64.');
        Store::require(strlen($encoded) <= 4 * intdiv($max + 2,3), 'O PDF do ERP excede 8 MiB.',413);
        $bytes = base64_decode($encoded,true);
        Store::require(is_string($bytes) && str_starts_with($bytes,'%PDF-'), 'O conteúdo enviado deve ser um PDF em Base64.');
        Store::require(strlen($bytes) <= $max, 'O PDF do ERP excede 8 MiB.',413);
        if (array_key_exists('sha256',$document)) {
            Store::require(is_string($document['sha256']) && hash_equals(hash('sha256',$bytes),strtolower($document['sha256'])),
                'O SHA-256 informado diverge do PDF recebido.');
        }
        return $bytes;
    }
    public static function checkImportable(string $bytes): void {
        require_once DIR . '/vendor/autoload.php';
        try {
            $copy = new Fpdi();
            self::appendPages($copy,$bytes);
            // Resolve the page resources now, before the invitation can be sent.
            $copy->Output('S');
        } catch (\Exception $error) {
            throw new Problem('Envie um PDF íntegro, sem senha e compatível com a importação de páginas.',422);
        }
    }
    private static function appendPages(Fpdi $out,string $bytes): void {
        $pages = $out->setSourceFile(StreamReader::createByString($bytes));
        Store::require($pages > 0,'O PDF deve conter ao menos uma página.');
        for ($i=1; $i <= $pages; $i++) {
            $page = $out->importPage($i);
            $size = $out->getTemplateSize($page);
            $out->AddPage($size['orientation'],[$size['width'],$size['height']]);
            $out->useTemplate($page);
        }
    }
    public static function render(string $html): string {
        require_once DIR . '/vendor/autoload.php';
        $opt = new Options();
        $opt->setIsRemoteEnabled(false);
        $opt->setIsPhpEnabled(false);
        $opt->setIsJavascriptEnabled(false);
        $opt->setAllowedProtocols([]);
        $opt->setDefaultFont('DejaVu Sans');
        $opt->setIsFontSubsettingEnabled(true);
        // Keep font metrics and temporary renderer files out of the plugin and document roots.
        $temp = rtrim(sys_get_temp_dir(), '/\\') . '/edu-sign-' . substr(Store::mac('pdf-cache', (string)get_current_blog_id()),0,24);
        if (!is_dir($temp) && !mkdir($temp,0700) && !is_dir($temp)) { throw new \RuntimeException('Cache de PDF indisponível.'); }
        $opt->setFontCache($temp);
        $opt->setTempDir($temp);
        $opt->setChroot([$temp]);
        $pdf = new Dompdf($opt);
        $css = '@page{margin:18mm 17mm}body{font:10pt/1.5 "DejaVu Sans",sans-serif;color:#1d2936}h1{font-size:18pt;line-height:1.2}h2{font-size:13pt}h3{font-size:11pt}h1,h2,h3{page-break-after:avoid}p{margin:0 0 7pt}table{width:100%;border-collapse:collapse}td,th{border:1px solid #bac6cc;padding:3pt;overflow-wrap:break-word}th{text-align:left}small{font-size:8pt;color:#42586a}blockquote{border-left:2pt solid #618879;padding-left:8pt}';
        $pdf->loadHtml('<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><style>'.$css.'</style></head><body>'.$html.'</body></html>', 'UTF-8');
        $pdf->setPaper('A4');
        $pdf->render();
        $out = $pdf->output();
        Store::require(strlen($out) < 8 * MB_IN_BYTES, 'O PDF excede o limite de 8 MB.');
        return $out;
    }
    public static function save(int $id, string $kind, string $bytes): string {
        Store::insert('documents', ['contract_id'=>$id,'kind'=>$kind,'pdf'=>$bytes]);
        return hash('sha256',$bytes);
    }
    public static function read(object $c, string $kind): string {
        global $wpdb;
        Store::require(in_array($kind,['issued','signed'],true), 'Documento inválido.',404);
        $bytes = $wpdb->get_var($wpdb->prepare('SELECT pdf FROM '.Store::t('documents').' WHERE contract_id=%d AND kind=%s',$c->id,$kind));
        Store::require(is_string($bytes), 'Documento indisponível.',404);
        $expected = $kind === 'issued' ? $c->issued_hash : $c->pdf_hash_sha256;
        Store::require($expected && hash_equals($expected,hash('sha256',$bytes)), 'A integridade do PDF exige conferência.',409);
        return $bytes;
    }
    public static function sign(object $c, array $acks, string $when): string {
        $issued = self::read($c,'issued');
        $html = '<h1>Certificado de trilha de auditoria</h1><p>'.esc_html($c->institution).'</p>';
        $fields = [
            'Contrato'=>Contracts::isErpPdf($c) ? $c->title.' · PDF recebido do ERP' : $c->title.' · versão '.$c->template_version, 'Protocolo'=>$c->uuid,
            'Aluno / RA'=>$c->student_name.' / '.$c->student_ref, 'Responsável'=>$c->guardian_name,
            'CPF'=>'***.***.***-'.$c->cpf_suffix, 'E-mail cadastrado'=>$c->guardian_email,
            'Assinatura (UTC)'=>$when, 'Método'=>'Link individual, conferência do CPF e da data de nascimento do responsável e código de uso único por e-mail.',
            'Manifestação de vontade'=>PortalService::DECLARATION,
        ];
        foreach ($fields as $key=>$val) { $html .= '<p><strong>'.esc_html($key).'</strong><br>'.esc_html($val).'</p>'; }
        $html .= '<h2>Ciências registradas</h2>';
        foreach ($acks as $a) { $html .= '<p>'.esc_html($a->label).'<br><small>'.esc_html($a->acknowledged_at).' UTC · '
            .(int)$a->scroll_percent.'% de avanço informado · '.(int)$a->time_spent_seconds.' s desde a abertura</small></p>'; }
        $html .= '<h2>Eventos anteriores ao fechamento</h2>';
        foreach (Audit::rows((int)$c->id) as $e) {
            $html .= '<p><small>'.(int)$e->seq.' · '.esc_html($e->created_at).' UTC</small><br>'.esc_html(Audit::label($e->event_type,$e->payload_json)).'</p>';
        }
        $html .= '<h2>Identificadores de integridade</h2><p>SHA-256 das páginas emitidas<br><small>'.chunk_split($c->issued_hash,32,' ').'</small></p>'
            .'<p>Selo HMAC da trilha anterior ao fechamento<br><small>'.chunk_split($c->audit_head,32,' ').'</small></p>'
            .'<p>O SHA-256 do arquivo completo acompanha o comprovante enviado por e-mail.</p>';
        $cert = self::render($html);
        $out = new Fpdi();
        $out->SetTitle('Contrato educacional - '.$c->uuid);
        $out->SetAutoPageBreak(false);
        self::appendPages($out,$issued);
        self::appendPages($out,$cert);
        return $out->Output('S');
    }
}
