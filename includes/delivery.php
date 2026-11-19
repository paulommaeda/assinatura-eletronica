<?php
namespace EduSign;
defined('ABSPATH') || exit;

final class Delivery {
    public static function queue(int $cid,string $kind): void {
        Store::insert('jobs',['contract_id'=>$cid,'kind'=>$kind,'state'=>'pending','due_at'=>Store::now()]);
    }
    public static function work(): void { self::run(0,12); }
    public static function run(int $cid,int $limit = 3): void {
        global $wpdb;
        $t = Store::t('jobs');
        $where = $cid ? $wpdb->prepare(' AND contract_id=%d',$cid) : '';
        $ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM $t WHERE state IN ('pending','running') AND due_at<=%s $where ORDER BY id LIMIT %d",Store::now(),$limit));
        foreach ($ids as $id) { self::deliver((int)$id); }
    }
    private static function deliver(int $id): void {
        global $wpdb;
        $jobs = Store::t('jobs'); $lease = Store::token();
        $claimed = $wpdb->query($wpdb->prepare("UPDATE $jobs SET state='running',lease=%s,due_at=%s,attempts=attempts+1
            WHERE id=%d AND state IN ('pending','running') AND due_at<=%s",$lease,Store::after(600),$id,Store::now()));
        if (!$claimed) { return; }
        $job = $wpdb->get_row($wpdb->prepare("SELECT * FROM $jobs WHERE id=%d",$id));
        $c = $wpdb->get_row($wpdb->prepare('SELECT * FROM '.Store::t('contracts').' WHERE id=%d',$job->contract_id));
        if (!$c) { return; }
        $event = ''; $ok = true;
        try {
            $status = Contracts::effectiveStatus($c);
            if ($job->kind === 'hook' && $status === 'signed') {
                do_action('edu_contract_signed',(int)$c->id,Contracts::summary($c));
                $event = 'HOOK_DELIVERED';
            } elseif ($job->kind === 'receipt' && $status === 'signed' && !$c->archived_at) {
                $message = "Olá, {$c->guardian_name}.\n\nSua assinatura foi registrada. Para baixar o PDF e o certificado, abra o link e confirme seu CPF.\n\n"
                    .Contracts::link($c)."\n\nProtocolo: {$c->uuid}\nSHA-256 do PDF final: {$c->pdf_hash_sha256}\nAssinado em {$c->signed_at} UTC.\n\n{$c->institution}";
                $ok = wp_mail($c->guardian_email,'Comprovante de assinatura · '.$c->institution,$message);
                $event = 'RECEIPT_SENT';
            } elseif ($job->kind === 'invitation' && in_array($status,['sent','viewed'],true) && !$c->archived_at) {
                $message = "Olá, {$c->guardian_name}.\n\n{$c->institution} disponibilizou um contrato educacional para sua leitura e assinatura. Abra o link individual e confirme o CPF cadastrado.\n\n"
                    .Contracts::link($c)."\n\nPrazo: {$c->expires_at} UTC.\nProtocolo: {$c->uuid}\n\n{$c->institution}";
                $ok = wp_mail($c->guardian_email,'Contrato disponível para assinatura · '.$c->institution,$message);
                $event = 'INVITATION_SENT';
            }
        } catch (\Throwable $e) { $ok = false; }
        Store::tx(function () use ($wpdb,$c,$job,$lease,$event,$ok) {
            $locked = Store::contract($c->uuid,true);
            $t = Store::t('jobs');
            $owner = $wpdb->get_var($wpdb->prepare("SELECT lease FROM $t WHERE id=%d FOR UPDATE",$job->id));
            if ($owner !== $lease) { return; }
            $fields = ['state'=>$ok ? 'done' : 'pending','lease'=>''];
            if (!$ok) { $fields['due_at'] = Store::after(min(86400,60 * 2 ** min(10,(int)$job->attempts))); }
            Store::update('jobs',$fields,['id'=>$job->id]);
            if (!$ok || $event !== '') { Audit::add($locked,$ok ? $event : 'DELIVERY_FAILED','system',['kind'=>$job->kind,'attempt'=>(int)$job->attempts]); }
        });
    }
}
