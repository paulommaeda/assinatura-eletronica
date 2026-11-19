<?php
namespace EduSign;
defined('ABSPATH') || exit;

final class Audit {
    public static function label(string $event): string {
        $names = ['CONTRACT_ISSUED'=>'Contrato emitido','CONTRACT_SENT'=>'Envio solicitado','INVITATION_SENT'=>'Convite aceito pelo serviço de e-mail',
            'IDENTITY_CONFIRMED'=>'Link e CPF conferidos','CONTRACT_VIEWED'=>'Contrato aberto para leitura','CLAUSES_ACKNOWLEDGED'=>'Ciências registradas',
            'OTP_REQUESTED'=>'Código de assinatura solicitado','OTP_SENT'=>'Código aceito pelo serviço de e-mail','OTP_FAILED'=>'Falha no envio do código',
            'OTP_REJECTED'=>'Tentativa de código inválido','OTP_VERIFIED'=>'Código de uso único validado','SIGNATURE_CONFIRMED'=>'Vontade de assinar confirmada',
            'CONTRACT_SIGNED'=>'PDF final selado','RECEIPT_SENT'=>'Comprovante aceito pelo serviço de e-mail','HOOK_DELIVERED'=>'Integração executada',
            'DELIVERY_FAILED'=>'Falha de entrega','CONTRACT_CANCELLED'=>'Contrato cancelado','CONTRACT_EXPIRED'=>'Prazo de assinatura encerrado',
            'CONTRACT_ARCHIVED'=>'Contrato arquivado','INVITATION_RENEWED'=>'Novo convite solicitado','RECEIPT_RENEWED'=>'Novo comprovante solicitado'];
        return $names[$event] ?? $event;
    }
    private static function digest(string $uuid, object $e): string {
        return Store::mac('audit-v1',$uuid,(int)$e->seq,$e->prev_hash,$e->created_at,$e->event_type,$e->actor_type,$e->ip_hash,$e->agent_hash,$e->payload_json);
    }
    // Callers hold the contract row lock for the whole transaction.
    public static function add(object $c, string $event, string $actor, array $data = []): void {
        if ($actor === 'operator') { $data['wp_user_id'] = get_current_user_id(); }
        $human = $actor !== 'system';
        if ($human && Store::settings()['record_connection']) {
            $data['connection'] = Store::connectionDetails();
        }
        $e = (object)['contract_id'=>(int)$c->id,'seq'=>(int)$c->event_count+1,'prev_hash'=>$c->audit_head,'event_type'=>$event,
            'actor_type'=>$actor,'ip_hash'=>$human ? Store::ip() : '','agent_hash'=>$human ? Store::agent() : '',
            'payload_json'=>Store::json($data),'created_at'=>Store::now()];
        $e->event_hash = self::digest($c->uuid,$e);
        Store::insert('audit_logs',(array)$e);
        $c->audit_head = $e->event_hash;
        $c->event_count = $e->seq;
        Store::update('contracts',['event_count'=>$c->event_count,'audit_head'=>$c->audit_head],['id'=>$c->id]);
    }
    public static function rows(int $id): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare('SELECT * FROM '.Store::t('audit_logs').' WHERE contract_id=%d ORDER BY seq',$id));
    }
    public static function ackDigest(array $rows): string {
        $values = [];
        foreach ($rows as $a) {
            $values[] = [(int)$a->session_id,$a->clause_key,$a->label,$a->acknowledged_at,(int)$a->scroll_percent,(int)$a->time_spent_seconds];
        }
        return hash('sha256',Store::json($values));
    }
    public static function verify(object $c): array {
        return self::inspect($c)['integrity'];
    }
    public static function evidence(string $uuid): array {
        return Store::tx(static function () use ($uuid) {
            $c = Store::contract($uuid, true);
            $contract = Contracts::summary($c) + [
                'title' => $c->title, 'institution' => $c->institution,
                'student_name' => $c->student_name, 'guardian_name' => $c->guardian_name,
                'guardian_email' => $c->guardian_email, 'cpf_masked' => '***.***.***-'.$c->cpf_suffix,
            ];
            return ['schema_version' => 1, 'contract' => $contract] + self::inspect($c);
        });
    }
    private static function inspect(object $c): array {
        global $wpdb;
        $ackGroups = [];
        $acks = $wpdb->get_results($wpdb->prepare('SELECT session_id,clause_key,label,acknowledged_at,scroll_percent,time_spent_seconds FROM '.Store::t('acknowledgments').' WHERE contract_id=%d ORDER BY id',$c->id));
        foreach ($acks as $a) { $ackGroups[$a->session_id][] = $a; }
        $prev = ''; $seq = 0; $signed = null; $issued = null; $signingSession = null;
        $rows = self::rows((int)$c->id);
        foreach ($rows as $e) {
            Store::require(++$seq === (int)$e->seq && $prev === $e->prev_hash && hash_equals(self::digest($c->uuid,$e),$e->event_hash),
                'Divergência na trilha no evento '.$e->seq.'.',409);
            $prev = $e->event_hash;
            if ($e->event_type === 'CLAUSES_ACKNOWLEDGED') {
                $entry = json_decode($e->payload_json,true);
                $sid = $entry['session'];
                Store::require(isset($ackGroups[$sid]) && self::ackDigest($ackGroups[$sid]) === $entry['acks_sha256'],
                    'Divergência nas declarações de ciência.',409);
                unset($ackGroups[$sid]);
            }
            if ($e->event_type === 'CONTRACT_ISSUED') { $issued = json_decode($e->payload_json,true); }
            if ($e->event_type === 'SIGNATURE_CONFIRMED') {
                $signingSession = (int)json_decode($e->payload_json, true)['session'];
            }
            if ($e->event_type === 'CONTRACT_SIGNED') { $signed = json_decode($e->payload_json,true); }
        }
        Store::require(!$ackGroups && $seq === (int)$c->event_count && $prev === $c->audit_head && ($issued['pdf_sha256'] ?? '') === $c->issued_hash
            && ($issued['snapshot_sha256'] ?? '') === Contracts::snapshotHash($c),'Divergência no fechamento da trilha.',409);
        Pdf::read($c,'issued');
        if ($c->status === 'signed' || $signed !== null) {
            Store::require($c->status === 'signed' && ($signed['pdf_sha256'] ?? '') === $c->pdf_hash_sha256 && ($signed['signed_at'] ?? '') === $c->signed_at,'Divergência no selo da assinatura.',409);
            Pdf::read($c,'signed');
        }
        $integrity = ['verified'=>true,'events'=>$seq,'audit_head'=>$prev,'issued_sha256'=>$c->issued_hash,'signed_sha256'=>$c->pdf_hash_sha256];
        return [
            'integrity' => $integrity, 'signing_session_id' => $signed ? $signingSession : null,
            'acknowledgments' => $acks, 'events' => $rows,
        ];
    }
}
