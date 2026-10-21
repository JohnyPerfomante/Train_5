<?php
declare(strict_types=1);
/** Small SMTP transport: STARTTLS/implicit TLS, AUTH LOGIN, UTF-8 MIME. */
function smtp_send(string $to, string $subject, string $body): void {
    $from=getenv('MAIL_FROM') ?: 'noreply@transport.test';
    foreach ([$from,$to] as $address) if (!filter_var($address,FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/',$address)) throw new RuntimeException('Invalid mail address');
    $host=getenv('SMTP_HOST') ?: 'mailpit'; $port=(int)(getenv('SMTP_PORT') ?: 1025);
    $tls=getenv('SMTP_TLS') ?: 'none';
    if (!in_array($tls,['none','tls','ssl'],true)) throw new RuntimeException('Invalid SMTP_TLS');
    $context=stream_context_create(['ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'peer_name'=>$host]]);
    $socket=stream_socket_client(($tls==='ssl'?'ssl://':'tcp://').$host.':'.$port,$errno,$errstr,8,STREAM_CLIENT_CONNECT,$context);
    if (!$socket) throw new RuntimeException('SMTP connection failed');
    stream_set_timeout($socket,8);
    $read=static function(array $codes) use ($socket): void {
        do {
            $line=fgets($socket,2048);
            if ($line===false || strlen($line)<4) throw new RuntimeException('SMTP response missing');
        } while ($line[3]==='-');
        if (!in_array((int)substr($line,0,3),$codes,true)) throw new RuntimeException('SMTP rejected command '.substr($line,0,3));
    };
    $write=static function(string $data) use ($socket): void {
        while ($data!=='') { $n=fwrite($socket,$data); if (!$n) throw new RuntimeException('SMTP write failed'); $data=substr($data,$n); }
    };
    $command=static function(string $line,array $codes) use ($write,$read): void { $write($line."\r\n"); $read($codes); };
    try {
        $read([220]); $command('EHLO transport.local',[250]);
        if ($tls==='tls') {
            $command('STARTTLS',[220]);
            if (!stream_socket_enable_crypto($socket,true,STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new RuntimeException('SMTP TLS failed');
            $command('EHLO transport.local',[250]);
        }
        if (getenv('SMTP_USER')) {
            if ($tls==='none') throw new RuntimeException('SMTP credentials require TLS');
            $command('AUTH LOGIN',[334]); $command(base64_encode(getenv('SMTP_USER')),[334]);
            $command(base64_encode(getenv('SMTP_PASS') ?: ''),[235]);
        }
        $command('MAIL FROM:<'.$from.'>',[250]); $command('RCPT TO:<'.$to.'>',[250,251]); $command('DATA',[354]);
        $message='From: Transport <'.$from.">\r\nTo: <".$to.">\r\nSubject: =?UTF-8?B?".base64_encode($subject)."?=\r\n".
            'Date: '.gmdate('r')."\r\nMessage-ID: <".bin2hex(random_bytes(16))."@transport.local>\r\n".
            "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n".
            chunk_split(base64_encode($body),76,"\r\n");
        $write($message."\r\n.\r\n"); $read([250]);
        // The DATA response is the delivery boundary; QUIT failure must not retry an accepted email.
        try { $command('QUIT',[221]); } catch (Throwable) {}
    } finally { fclose($socket); }
}
/** Outbox is durable. Failures remain pending; the CLI worker can retry. */
function dispatch_mail(?int $clientId=null, int $limit=5): int {
    $sent=0;
    for ($i=0;$i<$limit;$i++) {
        $id=null;
        try {
            db()->beginTransaction();
            $where=$clientId===null?'':' AND client_id=?';
            $job=run("SELECT * FROM mail_jobs WHERE status='pending' AND attempts<10".$where.' ORDER BY attempts,id LIMIT 1 FOR UPDATE SKIP LOCKED',$clientId===null?[]:[$clientId])->fetch();
            if (!$job) { db()->commit(); break; }
            $id=(int)$job['id'];
            $token=run('SELECT used_at,expires_at FROM email_tokens WHERE id=?',[$job['token_id']])->fetch();
            if (!$token || $token['used_at'] || $token['expires_at']<=gmdate('Y-m-d H:i:s')) {
                run("UPDATE mail_jobs SET status='discarded' WHERE id=?",[$id]); db()->commit(); continue;
            }
            smtp_send($job['recipient'],$job['subject'],$job['body']);
            run("UPDATE mail_jobs SET status='sent',attempts=attempts+1,sent_at=UTC_TIMESTAMP(),last_error=NULL WHERE id=?",[$id]);
            db()->commit(); $sent++;
        } catch (Throwable $e) {
            if (db()->inTransaction()) db()->rollBack();
            if ($id) run("UPDATE mail_jobs SET attempts=attempts+1,last_error='SMTP недоступний або відхилив лист' WHERE id=?",[$id]);
            break;
        }
    }
    return $sent;
}
