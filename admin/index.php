<?php
 declare(strict_types=1);
 require dirname(__DIR__).'/_app/leads.php';
 use function MegaExpert\{config,storage,mkdirPrivate,writeJson,checkOrigin};
 ini_set('display_errors','0');
 header('Cache-Control: no-store, private, max-age=0');
 header('X-Robots-Tag: noindex, nofollow, noarchive');
 header('X-Content-Type-Options: nosniff');
 header('Referrer-Policy: no-referrer');
 header("Content-Security-Policy: default-src 'none'; style-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
 function esc(mixed $s): string {return htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
 function fail(int $code,string $text): never {http_response_code($code);echo esc($text);exit;}
 function token(): string {return '<input type="hidden" name="csrf" value="'.esc($_SESSION['csrf']).'">';}
 function dateM(int $t): string {return (new DateTimeImmutable('@'.$t))->setTimezone(new DateTimeZone('Europe/Moscow'))->format('d.m.Y H:i');}
 function state(string $base,string $id): string {$s=@file_get_contents($base.'/admin-state/'.$id.'.json');$v=$s?json_decode($s,true):[];return in_array($v['status']??'', ['new','work','closed'],true)?$v['status']:'new';}
 function record(string $base,string $id): array {if(!preg_match('/^[a-f0-9]{32}$/D',$id))fail(404,'Заявка не найдена.');$f=$base.'/requests/'.$id.'.json';if(!is_file($f))fail(404,'Заявка не найдена.');$r=json_decode((string)file_get_contents($f),true,32,JSON_THROW_ON_ERROR);if(($r['id']??'')!==$id)fail(404,'Заявка не найдена.');return $r;}
 function attempt(string $base,string $key,bool $increment): bool {mkdirPrivate($base.'/admin-rates');$p=$base.'/admin-rates/'.$key.'.json';$f=fopen($p,'c+');if(!$f||!flock($f,LOCK_EX))throw new RuntimeException('Rate lock');try{$raw=stream_get_contents($f);$a=json_decode($raw?:'[]',true)?:[];$a=array_values(array_filter($a,fn($t)=>is_int($t)&&$t>time()-900));$ok=count($a)<10;if($increment&&$ok){$a[]=time();rewind($f);ftruncate($f,0);fwrite($f,json_encode($a));fflush($f);chmod($p,0600);}return $ok;}finally{flock($f,LOCK_UN);fclose($f);}}
 try {
 $https=($_SERVER['HTTPS']??'')==='on'||($_SERVER['SERVER_PORT']??'')==='443';
 if(!$https&&($_SERVER['REMOTE_ADDR']??'')!=='127.0.0.1')fail(400,'Используйте HTTPS.');
 $method=$_SERVER['REQUEST_METHOD']??'GET';if(!in_array($method,['GET','POST'],true))fail(405,'Метод не поддерживается.');
 session_name('mega_admin');session_start(['cookie_httponly'=>true,'cookie_secure'=>$https,'cookie_samesite'=>'Strict','cookie_path'=>'/admin/','use_strict_mode'=>true,'use_only_cookies'=>true]);
 $cfg=config();$base=storage();$auth=$cfg['admin']??[];
 if(empty($auth['password_hash'])||empty($auth['username']))fail(503,'Вход ещё не настроен.');
 if(!isset($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(32));
 if(isset($_SESSION['user'])&&((time()-($_SESSION['last']??0)>7200)||(time()-($_SESSION['started']??0)>28800)||!hash_equals($_SESSION['credential_version']??'',hash('sha256',$auth['password_hash'])))){$_SESSION=[];session_regenerate_id(true);$_SESSION['csrf']=bin2hex(random_bytes(32));}
 $error='';$action=is_string($_POST['action']??null)?$_POST['action']:'';
 if($method==='POST'){
 checkOrigin($_SERVER,$cfg);if(!is_string($_POST['csrf']??null)||!hash_equals($_SESSION['csrf'],$_POST['csrf']))fail(403,'Обновите страницу и повторите действие.');
 if($action==='login'){
 $key=hash_hmac('sha256',$_SERVER['REMOTE_ADDR']??'unknown',$cfg['storage_salt']);if(!attempt($base,$key,false)){header('Retry-After: 900');fail(429,'Слишком много попыток. Повторите через 15 минут.');}
 $u=$_POST['username']??'';$p=$_POST['password']??'';
 if(is_string($u)&&is_string($p)&&strlen($p)<=200&&hash_equals($auth['username'],$u)&&password_verify($p,$auth['password_hash'])){session_regenerate_id(true);$_SESSION=['user'=>$u,'started'=>time(),'last'=>time(),'csrf'=>bin2hex(random_bytes(32)),'credential_version'=>hash('sha256',$auth['password_hash'])];header('Location: ./',true,303);exit;}
 attempt($base,$key,true);$error='Неверный логин или пароль.';http_response_code(401);
 }elseif($action==='logout'){$_SESSION=[];session_destroy();setcookie('mega_admin','',['expires'=>time()-3600,'path'=>'/admin/','secure'=>$https,'httponly'=>true,'samesite'=>'Strict']);header('Location: ./',true,303);exit;}
 elseif($action==='status'){
 if(!isset($_SESSION['user']))fail(401,'Войдите в кабинет.');$id=is_string($_POST['id']??null)?$_POST['id']:'';record($base,$id);$s=$_POST['status']??'';if(!in_array($s,['new','work','closed'],true))fail(422,'Неизвестный статус.');mkdirPrivate($base.'/admin-state');writeJson($base.'/admin-state/'.$id.'.json',['status'=>$s,'updated_at'=>gmdate('c'),'by'=>$_SESSION['user']]);header('Location: ./?id='.$id.'&saved=1',true,303);exit;
 }else fail(400,'Неизвестное действие.');
 }
 $logged=isset($_SESSION['user']);if($logged)$_SESSION['last']=time();
 $id=is_string($_GET['id']??null)?$_GET['id']:'';
 if(!$logged&&($id!==''||isset($_GET['file'])))fail(401,'Войдите в кабинет.');
 if($logged&&isset($_GET['file'])){
 $r=record($base,$id);$index=$_GET['file'];if(!is_string($index)||!preg_match('/^\d{1,2}$/D',$index))fail(404,'Файл не найден.');$a=$r['attachments'][(int)$index]??null;if(!is_array($a)||!preg_match('~^uploads/'.preg_quote($id,'~').'/[1-5]\.bin$~D',$a['path']??''))fail(404,'Файл не найден.');
 $p=realpath($base.'/'.$a['path']);$dir=realpath($base.'/uploads/'.$id);if(!$p||!$dir||dirname($p)!==$dir||!is_file($p))fail(404,'Файл не найден.');
 session_write_close();header('Content-Type: application/octet-stream');header('Content-Disposition: attachment; filename="attachment.bin"; filename*=UTF-8\'\''.rawurlencode($a['name']));header('Content-Length: '.filesize($p));readfile($p);exit;
 }
 $labels=['new'=>'Новая','work'=>'В работе','closed'=>'Закрыта'];
 ?><!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Заявки — МегаЭксперт</title><link rel="stylesheet" href="admin.css"></head><body><main class="shell"><header class="top"><a class="brand" href="./">МегаЭксперт <span>/ заявки</span></a><?php if($logged):?><form method="post"><?=token()?><input type="hidden" name="action" value="logout"><button class="quiet">Выйти</button></form><?php endif;?></header>
 <?php if(!$logged):?><section class="login"><p class="eyebrow">Кабинет команды</p><h1>Заявки с сайта</h1><p class="muted">Войдите, чтобы посмотреть обращения и приложенные документы.</p><?php if($error):?><p role="alert" class="error"><?=esc($error)?></p><?php endif;?><form method="post"><?=token()?><input type="hidden" name="action" value="login"><label>Логин<input name="username" autocomplete="username" required maxlength="80"></label><label>Пароль<input type="password" name="password" autocomplete="current-password" required maxlength="200"></label><button class="primary">Войти</button></form></section>
 <?php elseif($id!==''): $r=record($base,$id);$l=$r['lead'];$s=state($base,$id);?>
 <a class="back" href="./">← Все заявки</a><div class="heading"><div><p class="eyebrow"><?=esc(dateM((int)$r['created_ts']))?> · Москва</p><h1><?=esc($l['name'])?></h1></div><span class="badge <?=esc($s)?>"><?=esc($labels[$s])?></span></div>
 <?php if(isset($_GET['saved'])):?><p class="success" role="status">Статус сохранён.</p><?php endif;?>
 <div class="detail-grid"><section class="panel"><h2>Обращение</h2><dl><dt>Контакт</dt><dd><?=esc($l['contact'])?></dd><dt>Компания</dt><dd><?=esc($l['company']?:'—')?></dd><dt>Задача</dt><dd class="task"><?=esc($l['task']?:'—')?></dd><dt>Референс-лист</dt><dd><?=($l['reference']??false)?'Запрошен':'Не запрошен'?></dd><dt>Номер заявки</dt><dd class="request-id"><?=esc($id)?></dd></dl><h2>Вложения</h2><?php if(!$r['attachments']):?><p class="muted">Нет вложений.</p><?php else:?><ul class="attachments"><?php foreach($r['attachments'] as $i=>$a):?><li><a href="?id=<?=esc($id)?>&amp;file=<?=$i?>"><?=esc($a['name'])?></a><span><?=number_format($a['size']/1024,0,',',' ')?> КБ</span></li><?php endforeach;?></ul><?php endif;?></section>
 <aside class="panel"><h2>Работа с заявкой</h2><form method="post"><?=token()?><input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?=esc($id)?>"><label>Статус<select name="status"><?php foreach($labels as $v=>$label):?><option value="<?=$v?>" <?=$s===$v?'selected':''?>><?=$label?></option><?php endforeach;?></select></label><button class="primary">Сохранить</button></form><h2 class="spaced">Уведомление</h2><p><?=($r['delivery']['email']['done']??false)?'Письмо отправлено почтовому серверу.':'Письмо ожидает отправки; заявка сохранена.'?></p><p class="muted">Список работает независимо от доставки писем.</p></aside></div>
 <?php else:
 $q=is_string($_GET['q']??null)?trim(mbSafe($_GET['q'])):'';$filter=is_string($_GET['status']??null)?$_GET['status']:'';if(!isset($labels[$filter]))$filter='';$rows=[];$counts=['new'=>0,'work'=>0,'closed'=>0];
 foreach(glob($base.'/requests/*.json')?:[] as $f){$rid=basename($f,'.json');if(!preg_match('/^[a-f0-9]{32}$/D',$rid))continue;$r=record($base,$rid);$s=state($base,$rid);$counts[$s]++;if($filter!==''&&$s!==$filter)continue;$hay=implode(' ',[$r['lead']['name'],$r['lead']['company'],$r['lead']['contact'],$r['lead']['task'],$rid]);if($q!==''&&!preg_match('/'.preg_quote($q,'/').'/iu',$hay))continue;$r['status']=$s;$rows[]=$r;}
 usort($rows,fn($a,$b)=>$b['created_ts']<=>$a['created_ts']);$total=count($rows);$pages=max(1,(int)ceil($total/25));$page=min($pages,max(1,(int)($_GET['page']??1)));$rows=array_slice($rows,($page-1)*25,25);
 ?><div class="heading"><div><p class="eyebrow">Обращения с сайта</p><h1>Заявки</h1></div><a class="quiet" href="./">Обновить</a></div><div class="counts"><?php foreach($labels as $v=>$label):?><a href="?status=<?=$v?>"><?=$label?><strong><?=$counts[$v]?></strong></a><?php endforeach;?></div>
 <form method="get" class="filters"><label class="search">Поиск<input name="q" value="<?=esc($q)?>" placeholder="Имя, компания, телефон или задача" maxlength="200"></label><label>Статус<select name="status"><option value="">Все</option><?php foreach($labels as $v=>$label):?><option value="<?=$v?>" <?=$filter===$v?'selected':''?>><?=$label?></option><?php endforeach;?></select></label><button class="primary">Найти</button><a class="quiet" href="./">Сбросить</a></form><p class="muted">Найдено: <?=$total?> · Время по Москве</p>
 <div class="table-wrap"><table><thead><tr><th>Дата</th><th>Заявитель</th><th>Контакт</th><th>Статус</th><th>Файлы</th><th></th></tr></thead><tbody><?php foreach($rows as $r):$l=$r['lead'];?><tr><td data-label="Дата"><?=esc(dateM((int)$r['created_ts']))?></td><td data-label="Заявитель"><strong><?=esc($l['name'])?></strong><small><?=esc($l['company'])?></small></td><td data-label="Контакт"><?=esc($l['contact'])?></td><td data-label="Статус"><span class="badge <?=esc($r['status'])?>"><?=esc($labels[$r['status']])?></span></td><td data-label="Файлы"><?=count($r['attachments'])?></td><td><a class="open" href="?id=<?=esc($r['id'])?>">Открыть →</a></td></tr><?php endforeach;?></tbody></table><?php if(!$rows):?><div class="empty"><h2><?=$total===0&&$q===''&&$filter===''?'Заявок пока нет':'Ничего не найдено'?></h2><p>Новые обращения появятся здесь после отправки формы на сайте.</p></div><?php endif;?></div>
 <?php if($pages>1):?><nav class="pagination" aria-label="Страницы"><?php if($page>1):?><a href="?<?=esc(http_build_query(['q'=>$q,'status'=>$filter,'page'=>$page-1]))?>">← Назад</a><?php endif;?><span><?=$page?> / <?=$pages?></span><?php if($page<$pages):?><a href="?<?=esc(http_build_query(['q'=>$q,'status'=>$filter,'page'=>$page+1]))?>">Далее →</a><?php endif;?></nav><?php endif;?>
 <p class="footnote">Доставленные заявки и вложения хранятся 30 дней. Заявки с ошибкой доставки остаются до проверки.</p>
 <?php endif;?></main></body></html><?php
 }catch(MegaExpert\FormError $e){http_response_code($e->status);echo esc($e->getMessage());}catch(Throwable){error_log('MegaExpert admin failed');http_response_code(503);echo 'Кабинет временно недоступен. Попробуйте позже.';}
 function mbSafe(string $s): string {if(!preg_match('//u',$s))return '';preg_match('/^.{0,200}/us',$s,$m);return $m[0]??'';}
