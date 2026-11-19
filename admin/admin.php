<?php
namespace EduSign;
defined('ABSPATH') || exit;

final class Admin {
    const PAGE = 'edu-sign';
    const STATES = ['draft'=>'Rascunho','sent'=>'Enviado','viewed'=>'Visualizado','signed'=>'Assinado','cancelled'=>'Cancelado','expired'=>'Expirado'];
    public static function menu(): void {
        add_menu_page('Contratos educacionais','Contratos educacionais',CAP,self::PAGE,[self::class,'page'],'dashicons-media-document',27);
    }
    public static function assets($hook): void {
        if ($hook === 'toplevel_page_'.self::PAGE) { wp_enqueue_style('edu-sign-admin',plugins_url('assets/admin.css',FILE),[],VERSION); }
    }
    private static function url(array $args=[]): string { return add_query_arg(['page'=>self::PAGE]+$args,admin_url('admin.php')); }
    private static function input(string $key,string $title,string $value='',string $type='text',bool $required=true): void {
        echo '<label class="es-field"><span>'.esc_html($title).'</span><input type="'.esc_attr($type).'" name="'.esc_attr($key).'" value="'.esc_attr($value).'" '.($required?'required':'').'></label>';
    }
    private static function begin(string $op,string $uuid='',bool $file=false): void {
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"'.($file?' enctype="multipart/form-data"':'').'>';
        echo '<input type="hidden" name="action" value="edu_sign_manage"><input type="hidden" name="op" value="'.esc_attr($op).'">';
        if ($uuid) { echo '<input type="hidden" name="uuid" value="'.esc_attr($uuid).'">'; }
        wp_nonce_field('edu_manage_'.$op.'_'.$uuid,'_wpnonce',false);
    }
    private static function end(string $label,bool $main=true): void {
        echo '<button type="submit" class="button '.($main?'button-primary':'').'">'.esc_html($label).'</button></form>';
    }
    private static function fileLink(string $uuid,string $kind,string $label): void {
        $url = add_query_arg(['action'=>'edu_sign_document','uuid'=>$uuid,'kind'=>$kind],admin_url('admin-post.php'));
        echo '<a class="button" href="'.esc_url(wp_nonce_url($url,'edu_file_'.$uuid.'_'.$kind)).'">'.esc_html($label).'</a> ';
    }
    public static function page(): void {
        if (!current_user_can(CAP)) { wp_die('Acesso restrito.', '', ['response'=>403]); }
        $tab = sanitize_key($_GET['tab'] ?? 'contracts');
        $tabs = ['contracts'=>'Contratos','new'=>'Emitir contrato','batch'=>'Envio em lote','templates'=>'Modelos'];
        if (current_user_can('manage_options')) { $tabs['settings']='Configurações'; }
        echo '<div class="wrap edu-admin"><h1>Contratos educacionais</h1><nav class="nav-tab-wrapper">';
        foreach ($tabs as $key=>$label) { echo '<a class="nav-tab '.($key===$tab?'nav-tab-active':'').'" href="'.esc_url(self::url(['tab'=>$key])).'">'.esc_html($label).'</a>'; }
        echo '</nav>';
        $flash_key = sanitize_key($_GET['notice_id'] ?? '');
        if ($flash_key) {
            $key = 'edu_flash_'.get_current_user_id().'_'.$flash_key;
            $notice = get_transient($key);
            if (is_array($notice)) {
                $level = $notice['level'] ?? (!empty($notice['ok']) ? 'success' : 'error');
                echo '<div role="status" class="notice notice-'.esc_attr($level).'"><p>'.nl2br(esc_html($notice['message'])).'</p></div>';
                delete_transient($key);
            }
        }
        try {
            Store::https();
            if ($tab === 'settings' && current_user_can('manage_options')) { self::settings(); }
            elseif ($tab === 'new' || $tab === 'batch') { self::issue($tab==='batch'); }
            elseif ($tab === 'templates') { self::templates(); }
            elseif (!empty($_GET['uuid'])) { self::detail(Store::contract(sanitize_text_field(wp_unslash($_GET['uuid'])))); }
            else { self::listing(); }
        } catch (Problem $e) { echo '<div class="notice notice-error"><p>'.esc_html($e->getMessage()).'</p></div>'; }
        echo '</div>';
    }
    private static function listing(): void {
        global $wpdb;
        $q = sanitize_text_field(wp_unslash($_GET['s'] ?? '')); $status = sanitize_key($_GET['status'] ?? '');
        $archived = isset($_GET['archived']); $page = max(1,absint($_GET['paged'] ?? 1));
        $where = [$archived ? 'archived_at IS NOT NULL' : 'archived_at IS NULL'];
        if (isset(self::STATES[$status])) {
            if ($status==='expired') { $where[] = $wpdb->prepare("(status='expired' OR (status IN ('sent','viewed') AND expires_at<=%s))",Store::now()); }
            else {
                $where[] = $wpdb->prepare('status=%s',$status);
                if (in_array($status,['sent','viewed'],true)) { $where[] = $wpdb->prepare('expires_at>%s',Store::now()); }
            }
        }
        if ($q !== '') {
            $like='%'.$wpdb->esc_like($q).'%';
            $where[]=$wpdb->prepare('(student_name LIKE %s OR student_ref LIKE %s OR guardian_name LIKE %s OR uuid=%s OR external_ref=%s)',$like,$like,$like,$q,$q);
        }
        $from = ' FROM '.Store::t('contracts').' WHERE '.implode(' AND ',$where);
        $total = (int)$wpdb->get_var('SELECT COUNT(*)'.$from);
        $rows = $wpdb->get_results('SELECT id,uuid,title,student_name,student_ref,guardian_name,status,expires_at,created_at'.$from.$wpdb->prepare(' ORDER BY id DESC LIMIT 25 OFFSET %d',($page-1)*25));
        echo '<h2>Acompanhamento</h2><form method="get" class="es-toolbar"><input type="hidden" name="page" value="edu-sign"><input name="s" aria-label="Buscar contratos" placeholder="Aluno, RA, responsável ou protocolo" value="'.esc_attr($q).'"><select name="status" aria-label="Situação"><option value="">Todas as situações</option>';
        foreach (self::STATES as $key=>$label) { echo '<option value="'.esc_attr($key).'" '.selected($key,$status,false).'>'.esc_html($label).'</option>'; }
        echo '</select><label><input type="checkbox" name="archived" value="1" '.checked($archived,true,false).'> Arquivados</label> <button class="button">Filtrar</button></form>';
        echo '<div class="es-scroll"><table class="widefat striped"><thead><tr><th>Contrato</th><th>Aluno / RA</th><th>Responsável</th><th>Situação</th><th>Emissão (UTC)</th></tr></thead><tbody>';
        foreach ($rows as $c) {
            echo '<tr><td><a href="'.esc_url(self::url(['uuid'=>$c->uuid])).'">'.esc_html($c->title).'</a></td><td>'.esc_html($c->student_name.' / '.$c->student_ref)
                .'</td><td>'.esc_html($c->guardian_name).'</td><td>'.esc_html(self::STATES[Contracts::effectiveStatus($c)]).'</td><td>'.esc_html($c->created_at).'</td></tr>';
        }
        if (!$rows) { echo '<tr><td colspan="5">Nenhum contrato encontrado.</td></tr>'; }
        echo '</tbody></table></div>';
        $pages = (int)ceil($total/25);
        if ($pages>1) { echo '<p>'.wp_kses_post(paginate_links(['total'=>$pages,'current'=>$page,'format'=>'','base'=>add_query_arg('paged','%#%')])).'</p>'; }
    }
    private static function detail(object $c): void {
        global $wpdb;
        $status = Contracts::effectiveStatus($c);
        echo '<p><a href="'.esc_url(self::url()).'">← Voltar aos contratos</a></p><h2>'.esc_html($c->title).'</h2><div class="es-card"><dl>';
        $fields = ['Protocolo'=>$c->uuid,'Situação'=>self::STATES[$status].($c->archived_at?' · Arquivado':''),'Versão do modelo'=>$c->template_version,
            'Aluno / RA'=>$c->student_name.' / '.$c->student_ref,'Responsável'=>$c->guardian_name.' · ***.***.***-'.$c->cpf_suffix,
            'E-mail'=>$c->guardian_email,'Referência externa'=>$c->external_ref,'Prazo (UTC)'=>$c->expires_at,'Assinatura (UTC)'=>$c->signed_at,'SHA-256 final'=>$c->pdf_hash_sha256];
        foreach ($fields as $label=>$v) { if ($v !== null && $v !== '') { echo '<dt>'.esc_html($label).'</dt><dd>'.esc_html((string)$v).'</dd>'; } }
        echo '</dl><div class="es-actions">';
        self::fileLink($c->uuid,'issued','PDF emitido');
        if ($status==='signed') { self::fileLink($c->uuid,'signed','PDF assinado'); }
        self::fileLink($c->uuid,'evidence','Conferir e baixar evidências');
        if (!$c->archived_at) {
            $actions = [];
            if ($status==='draft') { $actions['send']='Enviar convite'; }
            if (in_array($status,['sent','viewed','signed'],true)) { $actions['resend']=$status==='signed'?'Reenviar comprovante':'Renovar e reenviar convite'; }
            if (in_array($status,['draft','sent','viewed'],true)) { $actions['cancel']='Cancelar contrato'; }
            else { $actions['archive']='Arquivar'; }
            foreach ($actions as $op=>$label) { self::begin($op,$c->uuid); self::end($label,false); }
        }
        echo '</div></div><h2>Entregas e integração</h2><ul>';
        $jobs = $wpdb->get_results($wpdb->prepare('SELECT kind,state,attempts,due_at FROM '.Store::t('jobs').' WHERE contract_id=%d ORDER BY id DESC LIMIT 15',$c->id));
        $kinds=['invitation'=>'Convite','receipt'=>'Comprovante','hook'=>'Integração com ERP']; $states=['pending'=>'Em fila','running'=>'Em andamento','done'=>'Concluída'];
        foreach ($jobs as $job) { echo '<li>'.esc_html(($kinds[$job->kind]??$job->kind).' · '.($states[$job->state]??$job->state).' · tentativas: '.$job->attempts
            .($job->state==='pending'?' · próxima tentativa (UTC): '.$job->due_at:'')).'</li>'; }
        if (!$jobs) { echo '<li>Nenhuma entrega solicitada.</li>'; }
        echo '</ul><h2>Trilha de auditoria</h2><ol class="es-log">';
        foreach (Audit::rows((int)$c->id) as $e) { echo '<li><strong>'.esc_html(Audit::label($e->event_type)).'</strong> <small>'.esc_html($e->created_at).' UTC</small></li>'; }
        echo '</ol>';
        if ($c->archived_at && current_user_can('manage_options') && self::disposable($c)) {
            echo '<details class="es-card"><summary>Descarte definitivo após o prazo de guarda</summary><p>O descarte remove os PDFs, os dados pessoais e as evidências deste contrato. Confira as obrigações de guarda e eventuais litígios antes de prosseguir.</p>';
            self::begin('purge',$c->uuid); self::input('confirm_uuid','Digite o protocolo completo para autorizar a exclusão'); self::end('Excluir definitivamente',false); echo '</details>';
        }
    }
    private static function disposable(object $c): bool {
        $days=(int)Store::settings()['retention_days'];
        return $days>0 && $c->archived_at && ($c->signed_at ?: $c->expires_at) <= Store::after(-$days*DAY_IN_SECONDS);
    }
    private static function templates(): void {
        $models=Templates::active(); $id=absint($_GET['model'] ?? 0); $m=$id?Templates::get($id):null;
        echo '<h2>Modelos vigentes</h2><ul>';
        foreach ($models as $model) { echo '<li><a href="'.esc_url(self::url(['tab'=>'templates','model'=>$model->id])).'">'.esc_html($model->title.' · versão '.$model->version).'</a></li>'; }
        if (!$models) { echo '<li>Cadastre o texto aprovado pela instituição para emitir o primeiro contrato.</li>'; }
        echo '</ul><section class="es-card"><h2>'.($m?'Criar nova versão':'Novo modelo').'</h2>';
        if ($m) { echo '<p><a href="'.esc_url(self::url(['tab'=>'templates'])).'">Cadastrar outro modelo</a></p>'; }
        self::begin('template');
        echo '<input type="hidden" name="base_id" value="'.($m?(int)$m->id:0).'">';
        self::input('title','Título do contrato',$m?$m->title:'');
        echo '<label class="es-field"><span>Texto integral do contrato (HTML)</span><textarea name="html_content" rows="15" required placeholder="&lt;p&gt;A {{instituicao}} e {{responsavel}} celebram o presente contrato para {{aluno}}.&lt;/p&gt;">'.esc_textarea($m?$m->html_content:'').'</textarea></label>'
            .'<p>Use parágrafos, títulos, negrito, listas e tabelas. Variáveis disponíveis: <code>{{instituicao}}</code>';
        foreach (array_slice(Templates::VARIABLES,1) as $key) { echo ', <code>{{'.esc_html($key).'}}</code>'; }
        echo '. Crie campos próprios como <code>{{curso}}</code>.</p><label class="es-field"><span>Declarações de ciência · uma por linha</span><textarea name="clauses" rows="4" placeholder="Declaro que li as condições financeiras e os prazos de rescisão.">'.esc_textarea($m?implode("\n",(array)json_decode($m->required_clauses_json,true)):'').'</textarea></label>';
        self::end($m?'Salvar nova versão':'Salvar modelo'); echo '</section>';
    }
    private static function issue(bool $batch): void {
        $models=Templates::active();
        if (!$models) { echo '<p>Cadastre o primeiro modelo em <a href="'.esc_url(self::url(['tab'=>'templates'])).'">Modelos</a>.</p>'; return; }
        echo '<div class="es-card"><h2>'.($batch?'Emitir por arquivo CSV':'Emissão individual').'</h2>';
        self::begin($batch?'batch':'issue','',$batch);
        echo '<label class="es-field"><span>Modelo de contrato</span><select name="template_id" required>';
        foreach ($models as $m) { echo '<option value="'.(int)$m->id.'">'.esc_html($m->title.' · v'.$m->version).'</option>'; }
        echo '</select></label>';
        if ($batch) {
            echo '<p>Use CSV em UTF-8 separado por ponto e vírgula, com até 25 linhas de dados por envio. A referência externa identifica cada contrato em reimportações.</p><p><code>external_ref;student_ref;student_name;guardian_name;guardian_cpf;guardian_email;guardian_phone</code></p><p>Inclua variáveis do modelo em colunas adicionais, como <code>curso</code> e <code>ano_letivo</code>.</p><label class="es-field"><span>Arquivo CSV</span><input type="file" name="csv" accept=".csv,text/csv" required></label>';
        } else {
            echo '<div class="es-grid">';
            foreach (['student_ref'=>'RA / referência do aluno','student_name'=>'Nome do aluno','guardian_name'=>'Nome do responsável','guardian_cpf'=>'CPF do responsável','guardian_email'=>'E-mail do responsável','guardian_phone'=>'Telefone (opcional)','external_ref'=>'Referência externa (opcional)'] as $key=>$label) {
                self::input($key,$label,'',$key==='guardian_email'?'email':'text',!in_array($key,['guardian_phone','external_ref'],true));
            }
            echo '</div><label class="es-field"><span>Variáveis adicionais · uma por linha, no formato nome=valor</span><textarea name="custom" rows="3" placeholder="curso=Ensino Fundamental"></textarea></label>';
        }
        echo '<label class="es-field"><span>Após a emissão</span><select name="mode"><option value="send">Enviar convite por e-mail</option><option value="draft">Guardar como rascunho</option></select></label>';
        self::end($batch?'Processar lote':'Emitir contrato'); echo '</div>';
    }
    private static function settings(): void {
        $s=Store::settings();
        echo '<section class="es-card"><h2>Instituição e guarda de documentos</h2>';
        self::begin('settings'); self::input('institution','Nome da instituição',$s['institution']);
        self::input('valid_days','Validade do convite em dias (1 a 365)',(string)$s['valid_days'],'number');
        self::input('retention_days','Prazo de guarda em dias antes do arquivamento (0 para revisão manual)',(string)$s['retention_days'],'number');
        echo '<p>O prazo conta da assinatura ou, para contratos encerrados sem assinatura, do vencimento. O arquivamento encerra o acesso pelo portal. O descarte é uma ação separada do administrador.</p>';
        echo '<h3>Trilha de auditoria</h3><label><input type="checkbox" name="record_connection" value="1" '.checked((bool)$s['record_connection'],true,false).'> Incluir o IP da conexão e a identificação do navegador nos novos eventos de usuários.</label>';
        echo '<p>O padrão usa identificadores pseudonimizados. Com esta opção, os dados de conexão também ficam legíveis no dossiê acessível aos gestores. Em hospedagens com proxy, o IP pode ser o do proxy.</p>';
        echo '<p>O site usa <code>'.(defined('EDU_SIGN_KEY')?'EDU_SIGN_KEY':'wp_salt(auth)').'</code> para selar a trilha. Preserve a chave nos backups para poder conferir assinaturas antigas.</p>';
        self::end('Salvar configurações'); echo '</section>';
    }
    private static function batch(array $in): array {
        $file=$_FILES['csv'] ?? [];
        Store::require(($file['error'] ?? -1)===UPLOAD_ERR_OK && is_uploaded_file($file['tmp_name']) && $file['size']<=524288,'Envie um CSV de até 512 KB.');
        $fp=fopen($file['tmp_name'],'rb');
        $head=fgetcsv($fp,0,';','"',''); Store::require(is_array($head),'Cabeçalho CSV ausente.');
        $head[0]=preg_replace('/^\xEF\xBB\xBF/','',(string)$head[0]);
        $required=['external_ref','student_ref','student_name','guardian_name','guardian_cpf','guardian_email'];
        Store::require(!array_diff($required,$head) && count(array_unique($head))===count($head),'Revise o cabeçalho e as colunas obrigatórias do CSV.');
        $rows=[];
        while (($row=fgetcsv($fp,0,';','"',''))!==false) {
            if ($row===[null]) { continue; } $rows[]=$row;
            Store::require(count($rows)<=25,'O limite é de 25 contratos por lote.');
        }
        fclose($fp); Store::require((bool)$rows,'O CSV está vazio.');
        $results=[]; $known=array_merge($required,['guardian_phone']);
        $issued=0; $existing=0; $failed=0;
        foreach ($rows as $i=>$row) {
            try {
                Store::require(count($row)===count($head),'Quantidade de colunas divergente.');
                $record=array_combine($head,$row); Store::require(trim($record['external_ref'])!=='','Referência externa obrigatória.');
                $data=array_intersect_key($record,array_flip($known));
                $data['variables']=array_diff_key($record,array_flip($known));
                $data['template_id']=$in['template_id']; $data['mode']=$in['mode'];
                $r=Contracts::issue($data);
                if ($r['created']) { $issued++; } else { $existing++; }
                $msg=($r['created']?'Emitido':'Já emitido').' · '.$r['uuid'];
            } catch (Problem $e) { $failed++; $msg=$e->getMessage(); }
            catch (\Throwable $e) { $failed++; $msg='Falha de processamento. Reimporte com a mesma referência.'; }
            $results[]='Registro '.($i+1).' · '.$msg;
        }
        $summary = sprintf('Emitidos: %d. Já emitidos: %d. Com erro: %d.', $issued, $existing, $failed);
        $level = 'success';
        if ($failed) { $level = $failed === count($rows) ? 'error' : 'warning'; }
        return [$summary."\n".implode("\n",$results), $level];
    }
    public static function submit(): void {
        if (!current_user_can(CAP)) { wp_die('Acesso restrito.', '', ['response'=>403]); }
        $in=wp_unslash($_POST); $op=sanitize_key($in['op']??''); $uuid=sanitize_text_field($in['uuid']??'');
        check_admin_referer('edu_manage_'.$op.'_'.$uuid);
        $tab=['template'=>'templates','batch'=>'batch','issue'=>'new','settings'=>'settings'][$op]??'contracts';
        $args=['tab'=>$tab]; $message='Alteração registrada.'; $level='success';
        try {
            Store::https();
            switch ($op) {
                case 'template':
                    $in['clauses']=preg_split('/\R/u',trim($in['clauses']??''),-1,PREG_SPLIT_NO_EMPTY);
                    $args['model']=Templates::save($in); $message='Modelo salvo.'; break;
                case 'issue':
                    $in['variables']=[];
                    foreach (preg_split('/\R/u',trim($in['custom']??''),-1,PREG_SPLIT_NO_EMPTY) as $line) {
                        Store::require(str_contains($line,'='),'Use nome=valor nas variáveis.');
                        [$k,$v]=explode('=',$line,2); $in['variables'][trim($k)]=trim($v);
                    }
                    $r=Contracts::issue($in); Delivery::run($r['id']); $args=['uuid'=>$r['uuid']];
                    $message=$r['created']?'Contrato emitido.':'Esta referência já foi emitida.'; break;
                case 'batch': [$message,$level]=self::batch($in); break;
                case 'settings':
                    Store::require(current_user_can('manage_options'),'Acesso restrito.',403);
                    $valid=filter_var($in['valid_days'],FILTER_VALIDATE_INT); $ret=filter_var($in['retention_days'],FILTER_VALIDATE_INT);
                    Store::require($valid>=1 && $valid<=365 && $ret!==false && $ret>=0 && $ret<=36500,'Revise os prazos informados.');
                    update_option('edu_sign_settings',['institution'=>Store::text($in['institution'],'instituição'),'valid_days'=>$valid,'retention_days'=>$ret,
                        'record_connection'=>($in['record_connection'] ?? '') === '1'],false);
                    break;
                case 'purge':
                    Store::require(current_user_can('manage_options') && hash_equals($uuid,(string)($in['confirm_uuid']??'')),'Confirmação de descarte inválida.',403);
                    Store::tx(function () use ($uuid) {
                        global $wpdb;
                        $c=Store::contract($uuid,true); Store::require(self::disposable($c),'Prazo de guarda em vigor.',409);
                        foreach (['jobs','documents','acknowledgments','otps','sessions','audit_logs'] as $t) { Store::checked($wpdb->delete(Store::t($t),['contract_id'=>$c->id])); }
                        Store::checked($wpdb->delete(Store::t('contracts'),['id'=>$c->id]));
                    }); $message='Contrato e evidências excluídos.'; break;
                default:
                    Store::require(in_array($op,['send','resend','cancel','archive'],true),'Ação desconhecida.');
                    Contracts::change($uuid,$op); $c=Store::contract($uuid); Delivery::run((int)$c->id); $args=['uuid'=>$uuid];
            }
        } catch (Problem $e) { $level='error'; $message=$e->getMessage(); if ($uuid) { $args['uuid']=$uuid; } }
        catch (\Throwable $e) { $level='error'; $message='Falha na operação. Confira as extensões PHP e a disponibilidade do banco de dados.'; }
        $id=wp_generate_uuid4(); set_transient('edu_flash_'.get_current_user_id().'_'.$id,['level'=>$level,'message'=>$message],300);
        wp_safe_redirect(self::url($args+['notice_id'=>$id])); exit;
    }
    public static function download(): void {
        if (!current_user_can(CAP)) { wp_die('Acesso restrito.', '', ['response'=>403]); }
        $uuid=sanitize_text_field(wp_unslash($_GET['uuid']??'')); $kind=sanitize_key($_GET['kind']??'');
        check_admin_referer('edu_file_'.$uuid.'_'.$kind);
        try {
            Store::https(); nocache_headers(); header('X-Content-Type-Options: nosniff');
            if ($kind==='evidence') {
                $bytes=Store::json(Audit::evidence($uuid));
                $ext='json'; $mime='application/json';
            } else { $bytes=Pdf::read(Store::contract($uuid),$kind); $ext='pdf'; $mime='application/pdf'; }
            header('Content-Type: '.$mime); header('Content-Disposition: attachment; filename="contrato-'.$uuid.'-'.$kind.'.'.$ext.'"');
            echo $bytes; exit;
        } catch (Problem $e) { wp_die(esc_html($e->getMessage()),'', ['response'=>$e->getCode()]); }
    }
}
