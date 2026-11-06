<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/leads.php';
use function MegaExpert\{config,ready,storage,processRequest,housekeeping};
try{
    $cfg=config();if(!ready($cfg)){echo "Lead delivery is not enabled.\n";exit;}
    $base=storage();$guard=fopen($base.'/worker.lock','c');
    if(!$guard||!flock($guard,LOCK_EX|LOCK_NB)){if($guard)fclose($guard);exit;}
    $count=0;
    foreach(glob($base.'/requests/*.json')?:[] as $file){
        if($count>=20)break;
        $result=processRequest($base,basename($file,'.json'),$cfg);
        if($result['processed']??false)$count++;
    }
    housekeeping($base);
    flock($guard,LOCK_UN);fclose($guard);
    echo "Processed requests: ".$count."\n";
}catch(Throwable){fwrite(STDERR,"Lead worker failed; inspect private configuration and queue.\n");exit(1);}
