<?php
namespace EduSign;
defined('ABSPATH') || exit;

final class PortalService {
    const DECLARATION = 'Aceito este contrato e confirmo minha assinatura eletrônica com o código enviado ao meu e-mail.';

    private static function limit(string $scope,string $who,int $max,int $seconds): void {
        global $wpdb;
        $bucket = Store::mac('limit',$scope,$who,intdiv(time(),$seconds));
        $t = Store::t('limits');
        Store::checked($wpdb->query($wpdb->prepare("INSERT INTO $t (bucket,hits,expires_at) VALUES (%s,1,%s) ON DUPLICATE KEY UPDATE hits=hits+1",
            $bucket,Store::after(2*$seconds))));
        $hits = $wpdb->get_var($wpdb->prepare("SELECT hits FROM $t WHERE bucket=%s",$bucket));
        Store::require((int)$hits <= $max,'Muitas tentativas. Aguarde antes de tentar novamente.',429);
    }
    private static function available(object $c): bool {
        $status = Contracts::effectiveStatus($c);
        return !$c->archived_at && (in_array($status,['sent','viewed'],true) || ($status === 'signed' && $c->access_until > Store::now()));
    }
    public static function identify(string $uuid,array $data): array {
        self::limit('identify-ip',Store::ip(),80,900);
        $message = 'Confira o CPF e a data de nascimento do responsável e use o link enviado pela escola dentro do prazo. Se os dados estiverem corretos, entre em contato com a escola.';
        $c = Store::contract($uuid);
        $token = $data['invite'] ?? '';
        Store::require(self::available($c) && is_string($token) && hash_equals(Contracts::invite($c),$token),$message,403);
        self::limit('identify-contract',$uuid,12,900);
        try {
            $cpf = Store::cpf($data['cpf'] ?? '');
            $birthDate = Store::birthDate($data['birth_date'] ?? '');
        } catch (Problem $e) { throw new Problem($message,403); }
        return Store::tx(function () use ($uuid,$token,$cpf,$birthDate,$message) {
            $c = Store::contract($uuid,true);
            Store::require(self::available($c) && hash_equals(Contracts::invite($c),$token)
                && hash_equals($c->guardian_cpf_hash,Store::mac('cpf',$uuid,$cpf))
                && isset($c->guardian_birth_date_hash)
                && hash_equals($c->guardian_birth_date_hash,Store::mac('birth-date',$uuid,$birthDate)),$message,403);
            $session = Store::token();
            $sid = Store::insert('sessions',['contract_id'=>$c->id,'token_hash'=>Store::mac('cpf-birth-date-session',$uuid,$session),
                'created_at'=>Store::now(),'expires_at'=>Store::after(HOUR_IN_SECONDS)]);
            Audit::add($c,'IDENTITY_CONFIRMED','guardian',['session'=>$sid,'birth_date_verified'=>true]);
            return ['session'=>$session,'expires_in'=>HOUR_IN_SECONDS,'status'=>$c->status];
        });
    }
    private static function authorized(string $uuid,string $token,callable $operation) {
        global $wpdb;
        Store::require(preg_match('/^[a-f0-9]{64}$/D',$token) === 1,'Confirme o CPF e a data de nascimento do responsável para continuar.',401);
        return Store::tx(function () use ($wpdb,$uuid,$token,$operation) {
            $c = Store::contract($uuid,true);
            $s = $wpdb->get_row($wpdb->prepare('SELECT * FROM '.Store::t('sessions').' WHERE contract_id=%d AND token_hash=%s AND expires_at>%s',
                $c->id,Store::mac('cpf-birth-date-session',$uuid,$token),Store::now()));
            Store::require($s && isset($c->guardian_birth_date_hash) && self::available($c),
                'Abra o link do e-mail e confirme novamente seu CPF e sua data de nascimento.',401);
            return $operation($c,$s);
        });
    }
    public static function view(string $uuid,string $token): array {
        return self::authorized($uuid,$token,function ($c,$s) {
            if (!$s->viewed_at) {
                if ($c->status === 'sent') { Store::update('contracts',['status'=>'viewed'],['id'=>$c->id]); $c->status='viewed'; }
                Store::update('sessions',['viewed_at'=>Store::now()],['id'=>$s->id]);
                Audit::add($c,'CONTRACT_VIEWED','guardian',['session'=>(int)$s->id,'issued_sha256'=>$c->issued_hash]);
            }
            $at = strrpos($c->guardian_email,'@');
            $recipient = $at === false ? '' : substr($c->guardian_email,0,1).'•••'.substr($c->guardian_email,$at);
            return ['uuid'=>$c->uuid,'title'=>$c->title,'institution'=>$c->institution,'status'=>$c->status,'name'=>$c->guardian_name,
                'html'=>$c->rendered_html,'clauses'=>json_decode($c->clauses_json,true),'issued_sha256'=>$c->issued_hash,
                'acknowledged'=>count(self::acks($c,$s)) > 0,'code_recipient'=>$recipient,'declaration'=>self::DECLARATION,'signed_sha256'=>$c->pdf_hash_sha256,'signed_at'=>$c->signed_at];
        });
    }
    public static function document(string $uuid,string $token,string $kind): string {
        return self::authorized($uuid,$token,static function ($c) use ($kind) {
            Store::require($kind === 'issued' || ($kind === 'signed' && $c->status === 'signed'),'Documento indisponível.',404);
            return Pdf::read($c,$kind);
        });
    }
    public static function documentReceipt(string $uuid,string $session,string $hash): string {
        return Store::mac('issued-document-delivery',$uuid,$session,$hash);
    }
    private static function acks(object $c,object $s): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare('SELECT * FROM '.Store::t('acknowledgments').' WHERE contract_id=%d AND session_id=%d ORDER BY id',$c->id,$s->id));
    }
    private static function canSign(object $c,object $s): void {
        Store::require(in_array(Contracts::effectiveStatus($c),['sent','viewed'],true) && $s->viewed_at,
            'O contrato não está aberto para assinatura.',409);
    }
    public static function acknowledge(string $uuid,string $token,array $data): array {
        return self::authorized($uuid,$token,function ($c,$s) use ($data,$token) {
            self::canSign($c,$s);
            $clauses = json_decode($c->clauses_json,true);
            $keys = $data['clauses'] ?? null;
            Store::require(is_array($keys) && array_is_list($keys) && count($keys) === count($clauses)
                && array_reduce($keys,static fn($ok,$v)=>$ok && is_string($v) && isset($clauses[$v]),true)
                && count(array_unique($keys)) === count($clauses),'Confirme cada declaração de ciência.');
            Store::require(($data['issued_sha256'] ?? null) === $c->issued_hash,'Confira a versão apresentada do contrato.');
            $format = $data['format'] ?? '';
            $download = $format === 'download';
            if ($download) {
                $receipt = $data['document_receipt'] ?? null;
                Store::require(Contracts::isErpPdf($c) && is_string($receipt) &&
                    hash_equals(self::documentReceipt($c->uuid,$token,$c->issued_hash),$receipt),
                    'Baixe o PDF desta sessão antes de confirmar a leitura.');
            } else {
                Store::require($format === 'pdf' || ($format === 'text' && $c->rendered_html !== ''),'Formato de leitura inválido.');
                Store::require(($data['scroll_percent'] ?? null) === 100,'Percorra o contrato integral e confirme a leitura.');
            }
            if (!self::acks($c,$s)) {
                $elapsed = max(0,time()-strtotime($s->viewed_at.' UTC'));
                foreach ($clauses as $key=>$label) {
                    Store::insert('acknowledgments',['contract_id'=>$c->id,'session_id'=>$s->id,'clause_key'=>$key,'label'=>$label,
                        'acknowledged_at'=>Store::now(),'scroll_percent'=>$download ? 0 : 100,'time_spent_seconds'=>$elapsed]);
                }
                Audit::add($c,'CLAUSES_ACKNOWLEDGED','guardian',['session'=>(int)$s->id,'clauses'=>$clauses,'issued_sha256'=>$c->issued_hash,
                    'format'=>$format,'scroll_percent'=>$download ? null : 100,'elapsed_seconds'=>$elapsed,'acks_sha256'=>Audit::ackDigest(self::acks($c,$s))]);
            }
            return ['acknowledged'=>true];
        });
    }
    private static function requireAcks(object $c,object $s): array {
        self::canSign($c,$s);
        $acks = self::acks($c,$s);
        $recorded = [];
        foreach ($acks as $a) { $recorded[$a->clause_key] = $a->label; }
        Store::require($recorded === json_decode($c->clauses_json,true),'Registre todas as ciências nesta sessão antes de continuar.',409);
        return $acks;
    }
    public static function otp(string $uuid,string $token): array {
        global $wpdb;
        $code = str_pad((string)random_int(0,999999),6,'0',STR_PAD_LEFT);
        $dispatch = self::authorized($uuid,$token,function ($c,$s) use ($wpdb,$code) {
            self::requireAcks($c,$s);
            $t = Store::t('otps');
            $stats = $wpdb->get_row($wpdb->prepare("SELECT COUNT(*) AS total,MAX(created_at) AS latest FROM $t WHERE contract_id=%d AND created_at>%s",
                $c->id,Store::after(-HOUR_IN_SECONDS)));
            Store::require((int)$stats->total < 5,'O limite de cinco pedidos de código por hora foi atingido. Tente mais tarde.',429);
            Store::require(!$stats->latest || $stats->latest <= Store::after(-60),'Aguarde um minuto antes de solicitar outro código.',429);
            Store::checked($wpdb->query($wpdb->prepare("UPDATE $t SET state='revoked' WHERE contract_id=%d AND state IN ('sending','active')",$c->id)));
            $id = Store::insert('otps',['contract_id'=>$c->id,'session_id'=>$s->id,'channel'=>'email','token_hash'=>'','created_at'=>Store::now(),
                'expires_at'=>Store::after(600),'state'=>'sending']);
            Store::update('otps',['token_hash'=>Store::mac('otp',$c->uuid,(int)$s->id,$id,$code)],['id'=>$id]);
            Audit::add($c,'OTP_REQUESTED','guardian',['session'=>(int)$s->id,'challenge'=>$id]);
            return [$id,$c->guardian_email,$c->institution];
        });
        [$id,$email,$institution] = $dispatch;
        try { $sent = wp_mail($email,'Código de assinatura · '.$institution,"Seu código para assinar o contrato é $code.\n\nProtocolo: $uuid\nVálido por 10 minutos. Digite-o somente no portal onde solicitou a assinatura."); }
        catch (\Throwable $e) { $sent = false; }
        Store::tx(function () use ($wpdb,$uuid,$id,$sent) {
            $c = Store::contract($uuid,true);
            Store::update('otps',['state'=>$sent ? 'active' : 'failed'],['id'=>$id,'state'=>'sending']);
            Audit::add($c,$sent ? 'OTP_SENT' : 'OTP_FAILED','system',['challenge'=>$id,'channel'=>'email']);
        });
        Store::require((bool)$sent,'O serviço de e-mail do site recusou o envio do código. Tente novamente em um minuto. Se a falha persistir, entre em contato com a escola.',503);
        return ['sent'=>true,'expires_in'=>600,'resend_after'=>60];
    }
    public static function sign(string $uuid,string $token,array $data): array {
        global $wpdb;
        $result = self::authorized($uuid,$token,function ($c,$s) use ($wpdb,$data) {
            if ($c->status === 'signed') { return Contracts::summary($c); }
            $acks = self::requireAcks($c,$s);
            Store::require(($data['confirm'] ?? null) === true && ($data['issued_sha256'] ?? '') === $c->issued_hash,
                'Confirme expressamente a assinatura da versão apresentada.');
            $t = Store::t('otps');
            $o = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE contract_id=%d AND session_id=%d AND state='active' ORDER BY id DESC LIMIT 1",$c->id,$s->id));
            Store::require($o && $o->expires_at > Store::now() && (int)$o->attempts < 5,'Código indisponível ou expirado. Solicite outro.',403);
            $code = $data['code'] ?? null;
            $ok = is_string($code) && preg_match('/^[0-9]{6}$/D',$code) && hash_equals($o->token_hash,Store::mac('otp',$c->uuid,(int)$s->id,(int)$o->id,$code));
            if (!$ok) {
                $attempts = (int)$o->attempts + 1;
                Store::update('otps',['attempts'=>$attempts,'state'=>$attempts >= 5 ? 'locked' : 'active'],['id'=>$o->id]);
                Audit::add($c,'OTP_REJECTED','guardian',['challenge'=>(int)$o->id,'attempt'=>$attempts]);
                return new \WP_Error('edu_code','Código inválido ou indisponível.',403);
            }
            Audit::verify($c);
            $when = Store::now();
            Store::update('otps',['verified_at'=>$when,'state'=>'verified','token_hash'=>''],['id'=>$o->id]);
            Audit::add($c,'OTP_VERIFIED','guardian',['session'=>(int)$s->id,'challenge'=>(int)$o->id,'channel'=>'email']);
            Audit::add($c,'SIGNATURE_CONFIRMED','guardian',['session'=>(int)$s->id,'declaration'=>self::DECLARATION,'issued_sha256'=>$c->issued_hash]);
            $hash = Pdf::save((int)$c->id,'signed',Pdf::sign($c,$acks,$when));
            $fields = ['status'=>'signed','signed_at'=>$when,'pdf_hash_sha256'=>$hash,'invite_seed'=>Store::token(),'access_until'=>Store::after(30*DAY_IN_SECONDS)];
            Store::update('contracts',$fields,['id'=>$c->id]);
            foreach ($fields as $k=>$v) { $c->$k = $v; }
            Audit::add($c,'CONTRACT_SIGNED','guardian',['pdf_sha256'=>$hash,'issued_sha256'=>$c->issued_hash,'signed_at'=>$when]);
            Delivery::queue((int)$c->id,'receipt');
            Delivery::queue((int)$c->id,'hook');
            ErpWebhook::queue($c);
            return Contracts::summary($c);
        });
        Delivery::run($result['id']);
        return $result;
    }
}
