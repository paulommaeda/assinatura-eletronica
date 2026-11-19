<?php
namespace EduSign;
defined('ABSPATH') || exit;

final class Contracts {
    public static function snapshotHash(object $c): string {
        return hash('sha256',Store::json([$c->uuid,(int)$c->template_id,$c->title,(int)$c->template_version,$c->institution,
            $c->student_ref,$c->student_name,$c->guardian_name,$c->guardian_cpf_hash,$c->cpf_suffix,$c->guardian_email,
            $c->guardian_phone,$c->rendered_html,$c->clauses_json]));
    }
    public static function issue(array $input): array {
        global $wpdb;
        $data = [];
        foreach (['student_ref'=>['RA',100], 'student_name'=>['aluno',180], 'guardian_name'=>['responsável',180],
            'guardian_email'=>['e-mail',190], 'guardian_phone'=>['telefone',32], 'external_ref'=>['referência externa',160]] as $k=>$spec) {
            $data[$k] = Store::text($input[$k] ?? '',$spec[0],$spec[1],!in_array($k,['guardian_phone','external_ref'],true));
        }
        Store::require((bool)is_email($data['guardian_email']), 'Informe um e-mail válido para o responsável.');
        $cpf = Store::cpf($input['guardian_cpf'] ?? '');
        $id = absint($input['template_id'] ?? 0);
        $vars = $input['variables'] ?? [];
        Store::require(is_array($vars) && count($vars) <= 80, 'As variáveis devem ser um objeto com até 80 campos.');
        foreach ($vars as $key=>$value) {
            Store::require(is_string($key) && preg_match('/^[a-z][a-z0-9_]{0,48}$/D',$key) && !in_array($key,Templates::VARIABLES,true), 'Nome de variável personalizada inválido.');
            $vars[$key] = Store::text($value,$key,1500,false);
        }
        ksort($vars);
        $mode = $input['mode'] ?? 'send';
        Store::require(in_array($mode,['draft','send'],true),'Modo de emissão inválido.');
        $digest = Store::mac('issue',$data,$cpf,$id,$vars,$mode);
        $ref = $data['external_ref'];
        $existing = static function () use ($wpdb,$ref,$digest) {
            if ($ref === '') { return null; }
            $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM '.Store::t('contracts').' WHERE external_ref=%s',$ref));
            if (!$row) { return null; }
            Store::require(hash_equals($row->request_hash,$digest),'Essa referência externa já identifica um contrato com outros dados.',409);
            return self::summary($row) + ['created'=>false];
        };
        if ($prior = $existing()) { return $prior; }
        $model = Templates::get($id);
        Store::require($model->status === 'active','Escolha a versão vigente do modelo.',409);
        $uuid = wp_generate_uuid4();
        $settings = Store::settings();
        $values = ['instituicao'=>$settings['institution'],'aluno'=>$data['student_name'],'ra'=>$data['student_ref'],
            'responsavel'=>$data['guardian_name'],'cpf_mascarado'=>'***.***.***-'.substr($cpf,-2),'email'=>$data['guardian_email'],
            'telefone'=>$data['guardian_phone'],'data_emissao'=>wp_date('d/m/Y'),'protocolo'=>$uuid] + $vars;
        $body = '<p><small>'.esc_html($values['instituicao']).'</small></p><h1>'.esc_html($model->title).'</h1>'
            .'<p><small>Versão '.(int)$model->version.' · '.esc_html($uuid).'</small></p>'.Templates::render($model->html_content,$values);
        $clauses = ['leitura'=>'Declaro que tive acesso ao contrato integral e li seu conteúdo.'];
        foreach (json_decode($model->required_clauses_json,true) as $key=>$label) { $clauses[$key] = Templates::render($label,$values,false); }
        $bytes = Pdf::render($body);
        $data['external_ref'] = $ref !== '' ? $ref : null;
        $record = $data + ['uuid'=>$uuid,'request_hash'=>$digest,'template_id'=>$id,'template_version'=>(int)$model->version,'title'=>$model->title,
            'institution'=>$values['instituicao'],'guardian_cpf_hash'=>Store::mac('cpf',$uuid,$cpf),'cpf_suffix'=>substr($cpf,-2),
            'rendered_html'=>$body,'clauses_json'=>Store::json($clauses),'issued_hash'=>hash('sha256',$bytes),'invite_seed'=>Store::token(),
            'status'=>$mode === 'draft' ? 'draft' : 'sent','created_at'=>Store::now(),'expires_at'=>Store::after(DAY_IN_SECONDS*(int)$settings['valid_days'])];
        try {
            return Store::tx(function () use ($wpdb,$id,$record,$bytes,$mode) {
                $current = $wpdb->get_var($wpdb->prepare('SELECT status FROM '.Store::t('templates').' WHERE id=%d FOR UPDATE',$id));
                Store::require($current === 'active','O modelo foi revisado durante a emissão. Atualize a página.',409);
                $cid = Store::insert('contracts',$record);
                $c = Store::contract($record['uuid'],true);
                Pdf::save($cid,'issued',$bytes);
                Audit::add($c,'CONTRACT_ISSUED','operator',['pdf_sha256'=>$c->issued_hash,'snapshot_sha256'=>self::snapshotHash($c)]);
                if ($mode === 'send') {
                    Audit::add($c,'CONTRACT_SENT','operator');
                    Delivery::queue($cid,'invitation');
                }
                return self::summary($c) + ['created'=>true];
            });
        } catch (\RuntimeException $e) {
            if ($prior = $existing()) { return $prior; }
            throw $e;
        }
    }
    public static function summary(object $c): array {
        $status = self::effectiveStatus($c);
        return ['uuid'=>$c->uuid,'id'=>(int)$c->id,'external_ref'=>$c->external_ref,'student_ref'=>$c->student_ref,
            'status'=>$status,'template_id'=>(int)$c->template_id,'template_version'=>(int)$c->template_version,
            'expires_at'=>$c->expires_at,'signed_at'=>$c->signed_at,'pdf_sha256'=>$c->pdf_hash_sha256,'archived_at'=>$c->archived_at];
    }
    public static function effectiveStatus(object $c): string {
        return in_array($c->status,['sent','viewed'],true) && $c->expires_at <= Store::now() ? 'expired' : $c->status;
    }
    public static function invite(object $c): string { return Store::mac('invitation',$c->uuid,$c->invite_seed); }
    public static function link(object $c): string {
        return add_query_arg('edu_sign',$c->uuid,home_url('/','https')).'#'.self::invite($c);
    }
    public static function change(string $uuid, string $action, bool $automatic = false): void {
        global $wpdb;
        Store::tx(function () use ($wpdb,$uuid,$action,$automatic) {
            $c = Store::contract($uuid,true);
            $status = self::effectiveStatus($c);
            if ($automatic && $c->archived_at) { return; }
            Store::require(!$c->archived_at,'Contrato arquivado.',409);
            if ($action === 'expire') {
                if ($status !== 'expired' || $c->status === 'expired') { return; }
                $fields = ['status'=>'expired']; $event = 'CONTRACT_EXPIRED';
            } elseif ($action === 'cancel') {
                Store::require(in_array($status,['draft','sent','viewed'],true),'O contrato não está aberto para cancelamento.',409);
                $fields = ['status'=>'cancelled']; $event = 'CONTRACT_CANCELLED';
            } elseif ($action === 'archive') {
                Store::require(in_array($status,['signed','expired','cancelled'],true),'Encerre o contrato antes de arquivar.',409);
                $fields = ['archived_at'=>Store::now(),'status'=>$status]; $event = 'CONTRACT_ARCHIVED';
            } elseif ($action === 'send') {
                Store::require($status === 'draft','O contrato já foi encaminhado.',409);
                $fields = ['status'=>'sent','expires_at'=>Store::after((int)Store::settings()['valid_days']*DAY_IN_SECONDS)]; $event = 'CONTRACT_SENT';
                Delivery::queue((int)$c->id,'invitation');
            } elseif ($action === 'resend') {
                Store::require(in_array($status,['sent','viewed','signed'],true),'O contrato não está disponível para reenvio.',409);
                $kind = $status === 'signed' ? 'receipt' : 'invitation';
                $fields = ['invite_seed'=>Store::token()];
                if ($kind === 'receipt') { $fields['access_until'] = Store::after(30*DAY_IN_SECONDS); }
                $event = $kind === 'receipt' ? 'RECEIPT_RENEWED' : 'INVITATION_RENEWED';
                Delivery::queue((int)$c->id,$kind);
            } else { throw new Problem('Ação desconhecida.',422); }
            Store::update('contracts',$fields,['id'=>$c->id]);
            Store::checked($wpdb->delete(Store::t('sessions'),['contract_id'=>$c->id]));
            Audit::add($c,$event,$automatic ? 'system' : 'operator');
        });
    }
    public static function maintenance(): void {
        global $wpdb;
        $now = Store::now(); $table = Store::t('contracts');
        foreach ($wpdb->get_col($wpdb->prepare("SELECT uuid FROM $table WHERE archived_at IS NULL AND status IN ('sent','viewed') AND expires_at<=%s LIMIT 150",$now)) as $uuid) {
            self::change($uuid,'expire',true);
        }
        $days = (int)Store::settings()['retention_days'];
        if ($days > 0) {
            $cut = Store::after(-$days*DAY_IN_SECONDS);
            $sql = "SELECT uuid FROM $table WHERE archived_at IS NULL AND status IN ('signed','cancelled','expired') AND COALESCE(signed_at,expires_at)<=%s LIMIT 150";
            foreach ($wpdb->get_col($wpdb->prepare($sql,$cut)) as $uuid) { self::change($uuid,'archive',true); }
        }
        foreach (['sessions','limits','otps'] as $suffix) {
            Store::checked($wpdb->query($wpdb->prepare('DELETE FROM '.Store::t($suffix).' WHERE expires_at<%s LIMIT 1000',Store::after(-DAY_IN_SECONDS))));
        }
    }
}
