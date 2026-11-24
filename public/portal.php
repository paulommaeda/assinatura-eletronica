<?php
namespace EduSign;
defined('ABSPATH') || exit;

final class Portal {
    private static function readerModule($name): void {
        $files = ['viewer'=>'pdf.mjs', 'worker'=>'pdf.worker.mjs'];
        if (!is_string($name) || !isset($files[$name]) || !in_array($_SERVER['REQUEST_METHOD'] ?? '', ['GET','HEAD'], true)) {
            status_header(404);
            exit;
        }
        $path = DIR.'/assets/pdfjs/'.$files[$name];
        if (!is_file($path)) { status_header(404); exit; }
        header('Content-Type: text/javascript; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: public, max-age=86400');
        if ($_SERVER['REQUEST_METHOD'] === 'GET') { readfile($path); }
        exit;
    }

    public static function page(): void {
        if (isset($_GET['edu_sign_reader'])) { self::readerModule($_GET['edu_sign_reader']); }
        if (!isset($_GET['edu_sign'])) { return; }
        $uuid = is_string($_GET['edu_sign']) ? sanitize_text_field(wp_unslash($_GET['edu_sign'])) : '';
        if (!wp_is_uuid($uuid,4)) { wp_die('Link de contrato inválido.','Contrato',['response'=>404]); }
        if (!is_ssl()) { wp_die('Abra o link de contrato usando HTTPS.','Contrato',['response'=>403]); }
        nocache_headers(); status_header(200);
        header('Content-Type: text/html; charset=utf-8');
        header('Referrer-Policy: no-referrer'); header('X-Robots-Tag: noindex, nofollow, noarchive');
        header('X-Content-Type-Options: nosniff'); header('X-Frame-Options: DENY');
        header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self' 'unsafe-inline'; worker-src 'self' blob:; connect-src 'self' blob:; img-src 'self' data: blob:; font-src 'self' data: blob:; frame-src blob:; frame-ancestors 'none'; base-uri 'none'; form-action 'none'");
        $asset = static fn($file)=>plugins_url('assets/'.$file,FILE);
        $module = static fn($name)=>add_query_arg(['edu_sign_reader'=>$name,'v'=>VERSION],home_url('/','https'));
        $base = rest_url(Rest::NS.'/portal/'.$uuid.'/view','https');
        ?><!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Assinatura de contrato educacional</title><link rel="stylesheet" href="<?php echo esc_url($asset('portal.css')); ?>?v=<?php echo VERSION; ?>">
        <script defer src="<?php echo esc_url($asset('portal.js')); ?>?v=<?php echo VERSION; ?>"></script></head>
        <body><header class="bar"><div class="bar-inner"><span class="doc-mark" aria-hidden="true"></span><strong>Contratos educacionais</strong></div></header>
        <main id="portal" data-uuid="<?php echo esc_attr($uuid); ?>" data-api="<?php echo esc_url($base); ?>" data-pdfjs="<?php echo esc_url($module('viewer')); ?>" data-worker="<?php echo esc_url($module('worker')); ?>">
          <ol class="steps" aria-label="Etapas"><li aria-current="step">Identificação</li><li>Leitura</li><li>Assinatura</li></ol>
          <p id="notice" role="status" aria-live="polite" hidden></p>
          <noscript>Ative o JavaScript para abrir o contrato e assinar.</noscript>
          <section id="identity" class="panel narrow"><h1>Confirme sua identidade</h1><p>Informe o CPF e a data de nascimento do responsável pelo contrato, conforme o cadastro da escola.</p>
            <form id="identify"><label for="cpf">CPF do responsável</label><input id="cpf" inputmode="numeric" autocomplete="off" maxlength="14" placeholder="000.000.000-00" required>
            <label for="birth-date">Data de nascimento do responsável</label><input id="birth-date" type="date" autocomplete="bday" max="<?php echo esc_attr(wp_date('Y-m-d')); ?>" required>
            <button>Acessar contrato</button></form></section>
          <section id="reading" class="panel" hidden><p id="school" class="institution"></p><h1 id="title">Leitura do contrato</h1>
            <p id="reading-instruction" class="reading-instruction"><strong>Role até o final do contrato.</strong> Depois, marque as declarações e clique em <strong>Registrar ciência e continuar</strong> para receber o código por e-mail.</p>
            <div class="reader-tools"><button type="button" id="toggle" class="secondary">Usar texto acessível</button><button type="button" id="download-issued" class="secondary">Baixar PDF para leitura</button></div>
            <div id="reader" tabindex="0" aria-label="Texto integral do contrato"><div id="pages"></div><iframe id="native-pdf" title="Prévia do PDF original do contrato" hidden></iframe><article id="text" hidden></article></div>
            <p id="reading-end" class="hint" hidden>Fim do contrato</p>
            <p id="progress" class="hint" aria-live="polite"></p>
            <form id="ack-form"><fieldset><legend>Declarações de ciência</legend><div id="clauses"></div></fieldset><button id="ack-btn" disabled>Registrar ciência e continuar</button></form></section>
          <section id="signing" class="panel narrow" hidden><h1>Assinar contrato</h1><p>Valide a assinatura com o código de seis dígitos enviado a <strong id="code-recipient">seu e-mail cadastrado</strong>. O código é válido por dez minutos.</p>
            <button type="button" id="send-code" class="secondary">Enviar código por e-mail</button>
            <form id="sign-form"><label for="otp">Código de seis dígitos</label><input id="otp" inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="[0-9]{6}" required>
            <label class="check"><input type="checkbox" id="confirm" required><span id="declaration"></span></label><button>Confirmar assinatura</button></form></section>
          <section id="complete" class="panel narrow" hidden><div class="done-icon" aria-hidden="true">✓</div><h1>Contrato assinado</h1>
            <p>O PDF reúne o contrato e o certificado com as evidências da assinatura.</p><button id="download-signed">Baixar contrato assinado</button>
            <dl><dt>Protocolo</dt><dd id="protocol"></dd><dt>Assinatura (UTC)</dt><dd id="signed-at"></dd><dt>SHA-256 do arquivo</dt><dd id="digest"></dd></dl></section>
        </main></body></html><?php
        exit;
    }
}
