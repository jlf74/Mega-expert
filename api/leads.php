<?php
declare(strict_types=1);
require dirname(__DIR__).'/_app/leads.php';
use function MegaExpert\{config,ready,storage,writeJson,mkdirPrivate,uploadLimits,sizeLimit,validate,checkOrigin,checkToken,checkRate,uploadedFiles,saveRequest,processRequest};
use MegaExpert\FormError;
ini_set('display_errors','0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');
function reply(int $code,array $body): void { http_response_code($code);echo json_encode($body,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR); }
try {
    $cfg=config();$method=$_SERVER['REQUEST_METHOD']??'GET';
    if(!in_array($method,['GET','POST'],true)){header('Allow: GET, POST');throw new FormError(405,'Метод запроса не поддерживается.');}
    if(($_SERVER['HTTPS']??'')!=='on'&&($_SERVER['SERVER_PORT']??'')!=='443'&&($_SERVER['REMOTE_ADDR']??'')!=='127.0.0.1'){
        throw new FormError(400,'Откройте защищённую версию сайта.');
    }
    session_name('mega_lead');
    session_start(['cookie_httponly'=>true,'cookie_secure'=>(($_SERVER['HTTPS']??'')==='on'),'cookie_samesite'=>'Strict',
        'cookie_lifetime'=>0,'use_strict_mode'=>true,'use_only_cookies'=>true]);
    if($method==='GET'){
        if(!isset($_SESSION['csrf'])||($_SESSION['issued']??0)<time()-7200||isset($_SESSION['accepted'])){
            $_SESSION['csrf']=bin2hex(random_bytes(32));$_SESSION['submission_id']=bin2hex(random_bytes(16));
            $_SESSION['issued']=time();unset($_SESSION['accepted']);
        }
        $payload=['ready'=>ready($cfg),'csrf'=>$_SESSION['csrf'],'submission_id'=>$_SESSION['submission_id'],'limits'=>uploadLimits()];
        session_write_close();reply(200,$payload);exit;
    }
    checkOrigin($_SERVER,$cfg);
    $maxPost=sizeLimit((string)ini_get('post_max_size'));
    if((int)($_SERVER['CONTENT_LENGTH']??0)>min(11*1048576,$maxPost?:11*1048576))throw new FormError(413,'Общий размер вложений слишком большой.');
    checkToken($_POST,$_SESSION);
    if(!ready($cfg))throw new FormError(503,'Приём заявок через форму пока готовится. Напишите на info@mega-expert.ru или в Telegram.');
    $lead=validate($_POST);$id=$_POST['submission_id'];$base=storage();
    $key=hash_hmac('sha256',session_id(),$cfg['storage_salt']);
    if(isset($_SESSION['accepted'])){
        session_write_close();reply(200,['ok'=>true,'id'=>$id,'message'=>'Заявка уже принята. Мы свяжемся с вами.']);exit;
    }
    $uploads=uploadedFiles($_FILES['files']??[]);
    checkRate($base.'/rates',$_SERVER['REMOTE_ADDR']??'unknown',$cfg['storage_salt']);
    saveRequest($base,$id,$lead,$uploads,$key);
    $_SESSION['accepted']=$id;session_write_close();
    reply(202,['ok'=>true,'id'=>$id,'message'=>'Заявка принята. Мы свяжемся с вами.']);
    // Persist first; the CLI worker retries each channel independently after a failure.
    if(function_exists('fastcgi_finish_request')){fastcgi_finish_request();}
    else{while(ob_get_level()>0)ob_end_flush();flush();}
    ignore_user_abort(true);@set_time_limit(90);
    try{processRequest($base,$id,$cfg);}catch(Throwable){error_log('MegaExpert lead worker failed');}
} catch(FormError $e){
    if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
    if($e->status===429)header('Retry-After: 600');
    reply($e->status,['ok'=>false,'message'=>$e->getMessage()]);
} catch(Throwable){
    if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
    error_log('MegaExpert lead endpoint failed');
    reply(503,['ok'=>false,'message'=>'Не удалось принять заявку. Попробуйте позже или напишите на info@mega-expert.ru.']);
}
