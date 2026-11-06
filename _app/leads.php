<?php
declare(strict_types=1);

namespace MegaExpert;

use PHPMailer\PHPMailer\PHPMailer;

final class FormError extends \RuntimeException {
    public function __construct(public int $status, string $message) { parent::__construct($message); }
}

function config(): array {
    $file = dirname(__DIR__) . '/.private/config.json';
    if (!is_file($file)) return ['enabled' => false];
    $data = json_decode((string)file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);
    return is_array($data) ? $data : ['enabled' => false];
}
function ready(array $cfg): bool {
    return ($cfg['enabled'] ?? false) === true
        && filter_var($cfg['email']['to'] ?? '', FILTER_VALIDATE_EMAIL)
        && filter_var($cfg['email']['username'] ?? '', FILTER_VALIDATE_EMAIL)
        && ($cfg['email']['password'] ?? '') !== ''
        && preg_match('/^\d{5,20}:[A-Za-z0-9_-]{20,100}$/D', $cfg['telegram']['token'] ?? '') === 1
        && preg_match('/^-?\d{1,20}$/D', (string)($cfg['telegram']['chat_id'] ?? '')) === 1;
}
function storage(): string { return dirname(__DIR__) . '/.private'; }
function writeJson(string $file, array $data): void {
    $tmp = $file . '.' . bin2hex(random_bytes(6)) . '.tmp';
    $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if (file_put_contents($tmp, $encoded, LOCK_EX) !== strlen($encoded)) throw new \RuntimeException('Write failed');
    chmod($tmp, 0600);
    if (!rename($tmp, $file)) { @unlink($tmp); throw new \RuntimeException('Commit failed'); }
}
function mkdirPrivate(string $dir): void {
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) throw new \RuntimeException('Storage unavailable');
}
function sizeLimit(string $value): int {
    $n = (int)$value;
    return match (strtolower(substr(trim($value), -1))) { 'g' => $n*1073741824, 'm' => $n*1048576, 'k' => $n*1024, default => $n };
}
function uploadLimits(): array {
    $configured = sizeLimit((string)ini_get('upload_max_filesize'));
    $post = sizeLimit((string)ini_get('post_max_size'));
    return ['files' => 5, 'per_file' => min(5*1048576, $configured ?: 5*1048576),
        'total' => min(10*1048576, $post > 262144 ? $post-262144 : 10*1048576)];
}
function text(array $data, string $key, int $max, bool $required=false): string {
    $value = $data[$key] ?? '';
    if (!is_string($value) || !preg_match('//u', $value)) throw new FormError(422, 'Проверьте заполнение полей.');
    $value = trim(str_replace(["\r\n", "\r"], "\n", $value));
    if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) throw new FormError(422, 'Недопустимые символы в форме.');
    $length = preg_match_all('/./us', $value);
    if (($required && $value === '') || $length > $max) throw new FormError(422, 'Проверьте заполнение и длину полей.');
    return $value;
}
function validate(array $data): array {
    $name = text($data, 'name', 120, true);
    $company = text($data, 'company', 180);
    $contact = text($data, 'contact', 180, true);
    $task = text($data, 'task', 4000);
    foreach ([$name, $company, $contact] as $line) {
        if (str_contains($line, "\n")) throw new FormError(422, 'Контактные данные должны быть в одной строке.');
    }
    $phone = preg_replace('/[\s()+.\-]/u', '', $contact);
    if (!filter_var($contact, FILTER_VALIDATE_EMAIL) && !preg_match('/^\d{7,15}$/D', $phone)) {
        throw new FormError(422, 'Укажите корректный телефон или электронную почту.');
    }
    if (($data['consent'] ?? '') !== 'yes') throw new FormError(422, 'Для отправки необходимо согласие на обработку данных.');
    $source = text($data, 'request_source', 80);
    if (!in_array($source, ['contacts','project','header','hero','services','reference','reference-list'], true)) $source='project';
    return ['name'=>$name, 'company'=>$company, 'contact'=>$contact, 'task'=>$task,
        'reference'=>($data['reference'] ?? '')==='yes', 'source'=>$source,
        'consent'=>['accepted'=>true, 'version'=>'2026-11-06', 'document'=>'https://mega-expert.ru/documents/consent.html']];
}
function checkOrigin(array $server, array $cfg): void {
    $allowed=$cfg['origins'] ?? ['https://mega-expert.ru','https://www.mega-expert.ru'];
    $origin=$server['HTTP_ORIGIN'] ?? '';
    if ($origin === '' && isset($server['HTTP_REFERER'])) {
        $u=parse_url($server['HTTP_REFERER']);
        $origin=isset($u['scheme'],$u['host'])?$u['scheme'].'://'.$u['host'].(isset($u['port'])?':'.$u['port']:''):'';
    }
    if (!in_array($origin,$allowed,true)) throw new FormError(403,'Обновите страницу и отправьте форму ещё раз.');
    if (($server['HTTP_SEC_FETCH_SITE'] ?? '') === 'cross-site') throw new FormError(403,'Недопустимый источник запроса.');
}
function checkToken(array $post, array $session, ?int $now=null): void {
    $now ??= time();
    if (!is_string($post['csrf'] ?? null) || !hash_equals($session['csrf'] ?? '', $post['csrf'])
        || !is_string($post['submission_id'] ?? null) || !hash_equals($session['submission_id'] ?? '',$post['submission_id'])
        || !preg_match('/^[a-f0-9]{32}$/D',$post['submission_id'])
        || ($session['issued'] ?? 0) > $now-2 || ($session['issued'] ?? 0) < $now-7200
        || !is_string($post['website'] ?? null) || $post['website'] !== '') {
        throw new FormError(403,'Обновите страницу и попробуйте снова через несколько секунд.');
    }
}
function checkRate(string $dir, string $ip, string $salt, ?int $now=null): void {
    $now ??= time(); mkdirPrivate($dir);
    $path=$dir.'/'.hash_hmac('sha256',$ip,$salt).'.json';
    $f=fopen($path,'c+');
    if (!$f || !flock($f,LOCK_EX)) throw new \RuntimeException('Rate lock failed');
    try {
        $raw=stream_get_contents($f);
        $times=$raw===''?[]:json_decode($raw,true,8,JSON_THROW_ON_ERROR);
        $times=array_values(array_filter($times,fn($t)=>is_int($t)&&$t>$now-600));
        if (count($times)>=5) throw new FormError(429,'Слишком много обращений. Повторите отправку через 10 минут.');
        $times[]=$now; rewind($f); ftruncate($f,0);
        if (fwrite($f,json_encode($times,JSON_THROW_ON_ERROR))===false || !fflush($f)) throw new \RuntimeException('Rate write failed');
        chmod($path,0600);
    } finally {flock($f,LOCK_UN);fclose($f);}
}
function uploadedFiles(array $files): array {
    if (!$files || ($files['error'] ?? null)===UPLOAD_ERR_NO_FILE) return [];
    foreach (['name','error','size','tmp_name'] as $key) if (!isset($files[$key])||!is_array($files[$key])) throw new FormError(422,'Не удалось прочитать вложения.');
    $limits=uploadLimits();$items=[];$total=0;$finfo=new \finfo(FILEINFO_MIME_TYPE);
    if (count($files['name'])>$limits['files']) throw new FormError(422,'Можно прикрепить не более 5 файлов.');
    foreach ($files['name'] as $i=>$name) {
        $error=$files['error'][$i] ?? UPLOAD_ERR_PARTIAL;
        if ($error===UPLOAD_ERR_NO_FILE) continue;
        if ($error!==UPLOAD_ERR_OK) throw new FormError(422,'Не удалось загрузить файл. Проверьте его размер.');
        $tmp=$files['tmp_name'][$i]??'';
        if (!is_string($name)||!is_string($tmp)||!is_uploaded_file($tmp)||!preg_match('//u',$name)) throw new FormError(422,'Некорректное вложение.');
        $name=preg_replace('/[\/\\\\\x00-\x1F\x7F]/u','_', $name);
        if (strlen($name)>220) throw new FormError(422,'Сократите имя прикреплённого файла.');
        $ext=strtolower(pathinfo($name,PATHINFO_EXTENSION));
        $allowed=[
            'pdf'=>['application/pdf'],'doc'=>['application/msword','application/x-ole-storage','application/CDFV2','application/vnd.ms-office'],
            'xls'=>['application/vnd.ms-excel','application/x-ole-storage','application/CDFV2','application/vnd.ms-office'],
            'docx'=>['application/zip','application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
            'xlsx'=>['application/zip','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
            'dwg'=>['image/vnd.dwg','application/acad','application/x-acad','application/octet-stream'],
            'dxf'=>['image/vnd.dxf','application/dxf','text/plain','application/octet-stream'],
            'zip'=>['application/zip','application/x-zip-compressed'],
            'jpg'=>['image/jpeg'],'jpeg'=>['image/jpeg'],'png'=>['image/png']];
        $mime=$finfo->file($tmp);
        if (!isset($allowed[$ext])||!in_array($mime,$allowed[$ext],true)) throw new FormError(422,'Формат файла не поддерживается.');
        $size=filesize($tmp);$total+=$size;
        if ($size===0||$size>$limits['per_file']||$total>$limits['total']) throw new FormError(422,'Превышен допустимый размер вложений.');
        $items[]=['name'=>$name,'mime'=>$mime,'size'=>$size,'tmp'=>$tmp];
    }
    return $items;
}
function saveRequest(string $base, string $id, array $lead, array $uploads, string $sessionKey): array {
    mkdirPrivate($base.'/requests');mkdirPrivate($base.'/uploads/'.$id);
    $attachments=[];
    try {
        foreach($uploads as $i=>$file) {
            $relative='uploads/'.$id.'/'.($i+1).'.bin';
            if (!move_uploaded_file($file['tmp'],$base.'/'.$relative)) throw new \RuntimeException('Upload save failed');
            chmod($base.'/'.$relative,0600);
            $attachments[]=['path'=>$relative,'name'=>$file['name'],'mime'=>$file['mime'],'size'=>$file['size']];
        }
        $record=['id'=>$id,'created_at'=>gmdate('c'),'created_ts'=>time(),'session_key'=>$sessionKey,
            'lead'=>$lead,'attachments'=>$attachments,'delivery'=>[
                'email'=>['done'=>false,'attempts'=>0,'next_at'=>0],
                'telegram'=>['done'=>false,'attempts'=>0,'next_at'=>0,'parts'=>0]]];
        writeJson($base.'/requests/'.$id.'.json',$record);
        return $record;
    } catch(\Throwable $e) {
        foreach($attachments as $file) @unlink($base.'/'.$file['path']);
        @rmdir($base.'/uploads/'.$id);throw $e;
    }
}
function body(array $record, bool $telegram=false): string {
    $l=$record['lead'];$task=$l['task'];
    if ($telegram) {preg_match('/^.{0,2500}/us',$task,$match);$task=$match[0]??'';if($task!==$l['task'])$task.="\nПолное описание — в письме.";}
    return "Заявка МегаЭксперт № ".$record['id']."\nДата (UTC): ".$record['created_at']."\nИмя: ".$l['name']."\nКомпания: ".($l['company']?:'—')
        ."\nКонтакт: ".$l['contact']."\nРеференс-лист: ".($l['reference']?'да':'нет')
        ."\nИсточник: ".$l['source']."\n\nЗадача:\n".($task?:'—')
        ."\n\nСогласие: ".$l['consent']['version']."\nВложений: ".count($record['attachments']);
}
function sendMail(array $cfg,array $record,string $base): void {
    foreach(['Exception','SMTP','PHPMailer'] as $file) require_once __DIR__.'/vendor/PHPMailer/'.$file.'.php';
    $mail=new PHPMailer(true);$mail->isSMTP();
    // Fixed SMTP host prevents accidental use of another service or plaintext transport.
    $mail->Host='mail.hosting.reg.ru';$mail->Port=465;$mail->SMTPSecure=PHPMailer::ENCRYPTION_SMTPS;
    $mail->SMTPAuth=true;$mail->Username=$cfg['email']['username'];$mail->Password=$cfg['email']['password'];
    $mail->Timeout=10;$mail->Timelimit=12;$mail->CharSet='UTF-8';$mail->Encoding='base64';$mail->SMTPDebug=0;
    $mail->SMTPOptions=['ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'allow_self_signed'=>false]];
    $mail->setFrom($cfg['email']['username'],'МегаЭксперт — заявки с сайта');$mail->addAddress($cfg['email']['to']);
    if(filter_var($record['lead']['contact'],FILTER_VALIDATE_EMAIL))$mail->addReplyTo($record['lead']['contact']);
    $mail->Subject=($record['lead']['reference']?'Запрос референс-листа':'Заявка с сайта').' — МегаЭксперт';
    $mail->MessageID='<lead-'.$record['id'].'@mega-expert.ru>';$mail->Body=body($record);
    foreach($record['attachments'] as $file)$mail->addAttachment($base.'/'.$file['path'],$file['name'],'base64',$file['mime']);
    if(!$mail->send())throw new \RuntimeException('Email transport failed');
}
function telegramCall(array $cfg,string $method,array $data): void {
    $curl=curl_init('https://api.telegram.org/bot'.$cfg['telegram']['token'].'/'.$method);
    curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$data,CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>15,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
        CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_FOLLOWLOCATION=>false]);
    $response=curl_exec($curl);$code=curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);
    $json=is_string($response)?json_decode($response,true):null;
    if($code!==200||($json['ok']??false)!==true)throw new \RuntimeException('Telegram transport failed');
}
function sendTelegramPart(array $cfg,array $record,string $base,int $part): void {
    $data=['chat_id'=>(string)$cfg['telegram']['chat_id']];
    if(($cfg['telegram']['thread_id']??'')!=='')$data['message_thread_id']=(string)$cfg['telegram']['thread_id'];
    if($part===0){$data['text']=body($record,true);telegramCall($cfg,'sendMessage',$data);}
    else{$file=$record['attachments'][$part-1];$data['document']=new \CURLFile($base.'/'.$file['path'],$file['mime'],$file['name']);
        $data['caption']='Заявка № '.$record['id'];telegramCall($cfg,'sendDocument',$data);}
}
/** Per-channel checkpoints preserve successful deliveries if the other channel fails.
 * SMTP/Telegram cannot guarantee exactly-once delivery after an ambiguous timeout. */
function processRequest(string $base,string $id,array $cfg,?callable $mailTransport=null,?callable $tgTransport=null): array {
    if(!ready($cfg))return ['processed'=>false];
    if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new \RuntimeException('Invalid request id');
    $file=$base.'/requests/'.$id.'.json';if(!is_file($file))return ['processed'=>false];
    $lock=fopen($base.'/requests/'.$id.'.lock','c');
    if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)){if($lock)fclose($lock);return ['processed'=>false];}
    try{
        $record=json_decode((string)file_get_contents($file),true,32,JSON_THROW_ON_ERROR);
        $attempted=false;
        foreach(['email','telegram'] as $channel){
            $state=&$record['delivery'][$channel];
            if($state['done']||$state['next_at']>time())continue;
            // Retry for at most 7 days; leave failed records for the owner to inspect.
            if(time()-$record['created_ts']>7*86400)continue;
            try{
                $attempted=true;
                $state['attempts']++;
                if($channel==='email')($mailTransport??__NAMESPACE__.'\\sendMail')($cfg,$record,$base);
                else{
                    $count=count($record['attachments'])+1;
                    for($part=$state['parts'];$part<$count;$part++){
                        ($tgTransport??__NAMESPACE__.'\\sendTelegramPart')($cfg,$record,$base,$part);
                        $state['parts']=$part+1;writeJson($file,$record);
                    }
                }
                $state['done']=true;$state['delivered_at']=gmdate('c');unset($state['last_error']);
            }catch(\Throwable){
                $state['last_error']='transport_failed';$state['next_at']=time()+min(21600,60*(2**min(8,$state['attempts'])));
            }
            writeJson($file,$record);unset($state);
        }
        return ['processed'=>$attempted,'email'=>$record['delivery']['email']['done'],'telegram'=>$record['delivery']['telegram']['done']];
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}
function housekeeping(string $base): void {
    // Delivered copies are kept for 30 days; undelivered records need manual review.
    foreach(glob($base.'/requests/*.json')?:[] as $file){
        $id=basename($file,'.json');if(!preg_match('/^[a-f0-9]{32}$/D',$id))continue;
        $lock=fopen($base.'/requests/'.$id.'.lock','c');
        if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)){if($lock)fclose($lock);continue;}
        try{$record=json_decode((string)file_get_contents($file),true);
            if(($record['delivery']['email']['done']??false)&&($record['delivery']['telegram']['done']??false)
                &&($record['created_ts']??time())<time()-30*86400){
                foreach($record['attachments'] as $a)@unlink($base.'/'.$a['path']);
                @rmdir($base.'/uploads/'.$id);@unlink($file);
            }
        }finally{flock($lock,LOCK_UN);fclose($lock);}
    }
    foreach(glob($base.'/rates/*.json')?:[] as $file)if(filemtime($file)<time()-86400)@unlink($file);
}
