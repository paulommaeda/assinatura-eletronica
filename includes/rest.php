<?php
namespace EduSign;
defined('ABSPATH') || exit;

final class Rest {
    const NS = 'edu-sign/v1';
    const UUID = '(?P<uuid>[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12})';
    public static function register(): void {
        $admin = static fn()=>current_user_can(CAP);
        self::route('/templates','GET',$admin,static fn()=>Templates::active());
        self::route('/contracts','POST',$admin,static function ($params,$input) {
            $result = Contracts::issue($input);
            Delivery::run($result['id']);
            return new \WP_REST_Response($result,$result['created'] ? 201 : 200);
        });
        $path = '/contracts/'.self::UUID;
        self::route($path,'GET',$admin,static fn($params)=>Contracts::summary(Store::contract($params['uuid'])));
        self::route($path.'/evidence','GET',$admin,static fn($params)=>Audit::evidence($params['uuid']));
        self::route($path.'/document/(?P<kind>issued|signed)','GET',$admin,static fn($params)=>self::pdf(
            Pdf::read(Store::contract($params['uuid']),$params['kind']),$params['uuid'],$params['kind']));
        self::route($path.'/(?P<operation>send|cancel|resend|archive)','POST',$admin,static function ($params) {
            Contracts::change($params['uuid'],$params['operation']);
            $c = Store::contract($params['uuid']);
            Delivery::run((int)$c->id);
            return Contracts::summary($c);
        });
        $p = '/portal/'.self::UUID;
        self::route($p.'/identify','POST','__return_true',static fn($params,$data)=>PortalService::identify($params['uuid'],$data));
        self::route($p.'/view','POST','__return_true',static fn($params,$data,$session)=>PortalService::view($params['uuid'],$session));
        self::route($p.'/ack','POST','__return_true',static fn($params,$data,$session)=>PortalService::acknowledge($params['uuid'],$session,$data));
        self::route($p.'/otp','POST','__return_true',static fn($params,$data,$session)=>PortalService::otp($params['uuid'],$session));
        self::route($p.'/sign','POST','__return_true',static fn($params,$data,$session)=>PortalService::sign($params['uuid'],$session,$data));
        self::route($p.'/document/(?P<kind>issued|signed)','GET','__return_true',static fn($params,$data,$session)=>self::pdf(
            PortalService::document($params['uuid'],$session,$params['kind']),$params['uuid'],$params['kind']));
        add_filter('rest_pre_serve_request',[self::class,'serve'],10,4);
        add_filter('rest_post_dispatch',static function ($res,$server,$r) {
            if (str_starts_with($r->get_route(),'/'.self::NS.'/')) {
                $res->header('Cache-Control','no-store, private, max-age=0');
                $res->header('Referrer-Policy','no-referrer');
                $res->header('X-Robots-Tag','noindex, nofollow, noarchive');
                $res->header('X-Content-Type-Options','nosniff');
            }
            return $res;
        },10,3);
    }
    private static function route(string $path,string $method,callable $permission,callable $handler): void {
        register_rest_route(self::NS,$path,['methods'=>$method,'permission_callback'=>$permission,
            'callback'=>static function (\WP_REST_Request $r) use ($handler) {
                try {
                    Store::https();
                    $data = $r->get_json_params();
                    Store::require($data === null || is_array($data),'Envie um objeto JSON.');
                    return $handler($r->get_url_params(),$data ?? [],$r->get_header('x_edu_session') ?? '');
                } catch (Problem $e) { return new \WP_Error('edu_sign_request',$e->getMessage(),['status'=>$e->getCode()]); }
                catch (\Throwable $e) { return new \WP_Error('edu_sign_error','Não foi possível concluir a operação. Confira o ambiente e tente novamente.',['status'=>500]); }
            }]);
    }
    private static function pdf(string $bytes,string $uuid,string $kind): \WP_REST_Response {
        return new \WP_REST_Response($bytes,200,['Content-Type'=>'application/pdf','Content-Disposition'=>'attachment; filename="contrato-'.$uuid.'-'.$kind.'.pdf"']);
    }
    public static function serve($served,$result,$r,$server) {
        if ($served || !str_starts_with($r->get_route(),'/'.self::NS.'/') || !str_contains($r->get_route(),'/document/')) { return $served; }
        if ($result->get_status() === 200 && is_string($result->get_data()) && str_starts_with($result->get_data(),'%PDF-')) {
            echo $result->get_data(); // Exact, authenticated bytes. JSON encoding would change the digest.
            return true;
        }
        return $served;
    }
}
