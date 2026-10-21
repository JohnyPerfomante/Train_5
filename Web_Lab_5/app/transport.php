<?php
declare(strict_types=1);
require_once __DIR__.'/mailer.php';
function email_address(): string {
    $email=strtolower(field('email','Email',5,254));
    if (!filter_var($email,FILTER_VALIDATE_EMAIL)) throw new AppError('Вкажіть коректну email адресу.');
    return $email;
}
function phone_number(): string {
    $phone=field('phone','Телефон',10,20);
    if (!preg_match('/^\+?[0-9 ()-]{10,20}$/D',$phone) || strlen(preg_replace('/\D/','',$phone))<10) throw new AppError('Перевірте номер телефону: щонайменше 10 цифр.');
    return $phone;
}
/** Caller holds the client row lock and creates token + outbox in one transaction. */
function issue_token(array $c, string $kind, ?int $orderId=null): void {
    run('UPDATE email_tokens SET used_at=UTC_TIMESTAMP() WHERE client_id=? AND kind=? AND order_id <=> ? AND used_at IS NULL',[$c['id'],$kind,$orderId]);
    $raw=bin2hex(random_bytes(32));
    $expiry=gmdate('Y-m-d H:i:s',time()+($kind==='verify'?86400:3600));
    run('INSERT INTO email_tokens (client_id,order_id,kind,token_hash,expires_at) VALUES (?,?,?,?,?)',[$c['id'],$orderId,$kind,hash('sha256',$raw),$expiry]);
    $tokenId=(int)db()->lastInsertId();
    $url=rtrim(getenv('APP_URL') ?: 'http://127.0.0.1:8086','/').'/?page=confirm&token='.$raw;
    $subject=$kind==='verify'?'Підтвердження email у Transport':'Підтвердження замовлення '.order_number($orderId);
    $body="Вітаємо, ".$c['full_name']."!\n\n";
    if ($kind==='verify') $body.="Підтвердьте email, щоб оформлювати транспортні замовлення.\nПосилання діє 24 години.\n";
    else {
        $o=run('SELECT o.*,s.name service FROM orders o JOIN services s ON s.id=o.service_id WHERE o.id=?',[$orderId])->fetch();
        $body.=order_number($orderId)."\nПослуга: ".$o['service']."\nМаршрут: ".$o['origin'].' → '.$o['destination']."\nВиїзд: ".local_date($o['departure_at'])." (Київ)\nПасажирів: ".$o['passengers']."\n\nПосилання діє 1 годину.\n";
    }
    $body.="\nВідкрийте посилання та натисніть кнопку підтвердження:\n".$url."\n\nЯкщо ви не робили цей запит, проігноруйте лист.\nTransport — транспортні послуги";
    run('INSERT INTO mail_jobs (client_id,token_id,recipient,subject,body) VALUES (?,?,?,?,?)',[$c['id'],$tokenId,$c['email'],$subject,$body]);
}
function register_client(): int {
    $name=field('full_name',"Ім’я та прізвище",3,120); $email=email_address(); $phone=phone_number(); $password=input('password',false);
    if (strlen($password)<10 || strlen($password)>72) throw new AppError('Пароль має містити від 10 до 72 байтів.');
    if ($password!==input('password_confirmation',false)) throw new AppError('Паролі не збігаються.');
    if (input('consent')!=='1') throw new AppError('Потрібна згода на обробку контактних даних для замовлення.');
    $hash=password_hash($password,PASSWORD_DEFAULT);
    try {
        return transaction(function() use ($name,$email,$phone,$hash) {
            run('INSERT INTO clients (full_name,email,phone,password_hash) VALUES (?,?,?,?)',[$name,$email,$phone,$hash]);
            $id=(int)db()->lastInsertId();
            issue_token(['id'=>$id,'full_name'=>$name,'email'=>$email],'verify');
            session_regenerate_id(true); $_SESSION['client_id']=$id;
            return $id;
        });
    } catch (PDOException $e) {
        if ($e->getCode()==='23000') throw new AppError('Цю email адресу вже зареєстровано. Увійдіть до кабінету.');
        throw $e;
    }
}
function login_client(): void {
    $email=email_address(); $password=input('password',false);
    $bucket=hash('sha256',$email.'|'.($_SERVER['REMOTE_ADDR'] ?? 'local'));
    $result=transaction(function() use ($email,$password,$bucket) {
        run('INSERT IGNORE INTO login_attempts (bucket) VALUES (?)',[$bucket]);
        $b=run('SELECT * FROM login_attempts WHERE bucket=? FOR UPDATE',[$bucket])->fetch();
        if (strtotime($b['window_start'])<time()-600) { run('UPDATE login_attempts SET attempts=0,window_start=UTC_TIMESTAMP() WHERE bucket=?',[$bucket]); $b['attempts']=0; }
        if ($b['attempts']>=5) return ['limited'=>true];
        $c=run('SELECT * FROM clients WHERE email=?',[$email])->fetch();
        $fallback='$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi';
        if (!password_verify($password,$c['password_hash'] ?? $fallback) || !$c) {
            run('UPDATE login_attempts SET attempts=attempts+1 WHERE bucket=?',[$bucket]); return [];
        }
        run('DELETE FROM login_attempts WHERE bucket=?',[$bucket]); return $c;
    });
    if (isset($result['limited'])) throw new AppError('Забагато спроб. Повторіть вхід через 10 хвилин.',429);
    if (!$result) throw new AppError('Неправильний email або пароль.',401);
    session_regenerate_id(true); $_SESSION['client_id']=(int)$result['id'];
}
function save_profile(): void {
    $c=require_client(); $name=field('full_name',"Ім’я та прізвище",3,120); $phone=phone_number();
    run('UPDATE clients SET full_name=?,phone=? WHERE id=?',[$name,$phone,$c['id']]);
}
function owned_order(int $id,int $clientId,bool $lock=false): array {
    $o=run('SELECT * FROM orders WHERE id=? AND client_id=?'.($lock?' FOR UPDATE':''),[$id,$clientId])->fetch();
    if (!$o) throw new AppError('Замовлення не знайдено.',404);
    return $o;
}
function save_order(): int {
    $c=require_client(true); $id=input('id')===''?null:integer('id');
    $serviceId=integer('service_id'); $service=run('SELECT * FROM services WHERE id=?',[$serviceId])->fetch();
    if (!$service) throw new AppError('Оберіть доступну послугу.');
    $origin=field('origin','Звідки',3,150); $destination=field('destination','Куди',3,150);
    if (strtolower($origin)===strtolower($destination)) throw new AppError('Пункти відправлення і призначення мають відрізнятися.');
    $passengers=integer('passengers',1,(int)$service['max_passengers']); $notes=field('notes','Коментар',0,500);
    $raw=input('departure_at'); $date=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$raw,new DateTimeZone('Europe/Kyiv'));
    if (!$date || $date->format('Y-m-d\TH:i')!==$raw || $date->getTimestamp()<time()+3600 || $date->getTimestamp()>time()+31536000) throw new AppError('Виїзд: від 1 години до 1 року від поточного часу. Часовий пояс — Київ.');
    $utc=$date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    return transaction(function() use ($c,$id,$serviceId,$origin,$destination,$passengers,$notes,$utc) {
        run('SELECT id FROM clients WHERE id=? FOR UPDATE',[$c['id']]);
        if ($id) {
            $o=owned_order($id,(int)$c['id'],true);
            if ($o['status']==='cancelled') throw new AppError('Скасоване замовлення не можна змінити.');
            run("UPDATE orders SET service_id=?,origin=?,destination=?,departure_at=?,passengers=?,notes=?,status='awaiting_email' WHERE id=? AND client_id=?",[$serviceId,$origin,$destination,$utc,$passengers,$notes,$id,$c['id']]);
        } else {
            run('INSERT INTO orders (client_id,service_id,origin,destination,departure_at,passengers,notes) VALUES (?,?,?,?,?,?,?)',[$c['id'],$serviceId,$origin,$destination,$utc,$passengers,$notes]);
            $id=(int)db()->lastInsertId();
        }
        issue_token($c,'order',$id); return $id;
    });
}
function resend_confirmation(): void {
    $c=require_client(); $id=input('id')===''?null:integer('id'); $kind=$id?'order':'verify';
    transaction(function() use ($c,$id,$kind) {
        run('SELECT id FROM clients WHERE id=? FOR UPDATE',[$c['id']]);
        if ($id) { $o=owned_order($id,(int)$c['id'],true); if ($o['status']!=='awaiting_email') throw new AppError('Замовлення не очікує підтвердження.'); }
        elseif ($c['email_verified_at']) throw new AppError('Email уже підтверджено.');
        $last=run('SELECT created_at FROM email_tokens WHERE client_id=? AND kind=? AND order_id <=> ? ORDER BY id DESC LIMIT 1',[$c['id'],$kind,$id])->fetchColumn();
        if ($last && strtotime($last)>time()-60) throw new AppError('Повторний лист доступний через 60 секунд.',429);
        issue_token($c,$kind,$id);
    });
}
function token_data(string $raw,bool $lock=false): array {
    if (!preg_match('/^[a-f0-9]{64}$/D',$raw)) throw new AppError('Посилання недійсне або строк його дії минув.');
    $t=run('SELECT * FROM email_tokens WHERE token_hash=?'.($lock?' FOR UPDATE':''),[hash('sha256',$raw)])->fetch();
    if (!$t || $t['used_at'] || $t['expires_at']<=gmdate('Y-m-d H:i:s')) throw new AppError('Посилання вже використано або строк його дії минув. Запросіть новий лист у кабінеті.');
    return $t;
}
function confirm_token(string $raw): string {
    return transaction(function() use ($raw) {
        $snapshot=token_data($raw);
        run('SELECT id FROM clients WHERE id=? FOR UPDATE',[$snapshot['client_id']]);
        if ($snapshot['order_id']) $o=owned_order((int)$snapshot['order_id'],(int)$snapshot['client_id'],true);
        $t=token_data($raw,true);
        if ($t['kind']==='verify') {
            run('UPDATE clients SET email_verified_at=COALESCE(email_verified_at,UTC_TIMESTAMP()) WHERE id=?',[$t['client_id']]);
            $message='Email успішно підтверджено. Тепер можна оформити замовлення.';
        } else {
            if ($o['status']!=='awaiting_email') throw new AppError('Це замовлення вже оброблено.');
            if (strtotime($o['departure_at'])<=time()) throw new AppError('Час виїзду вже минув. Оновіть замовлення в кабінеті.');
            run("UPDATE orders SET status='confirmed' WHERE id=? AND client_id=?",[$t['order_id'],$t['client_id']]);
            $message='Замовлення '.order_number((int)$t['order_id']).' підтверджено.';
        }
        run('UPDATE email_tokens SET used_at=UTC_TIMESTAMP() WHERE id=?',[$t['id']]); return $message;
    });
}
function change_order(string $action): void {
    $c=require_client(true); $id=integer('id');
    transaction(function() use ($c,$id,$action) {
        run('SELECT id FROM clients WHERE id=? FOR UPDATE',[$c['id']]); $o=owned_order($id,(int)$c['id'],true);
        if ($action==='cancel') {
            if ($o['status']==='cancelled') throw new AppError('Замовлення вже скасовано.');
            run("UPDATE orders SET status='cancelled' WHERE id=? AND client_id=?",[$id,$c['id']]);
            run('UPDATE email_tokens SET used_at=UTC_TIMESTAMP() WHERE order_id=? AND used_at IS NULL',[$id]);
        } elseif ($action==='delete') {
            if ($o['status']!=='cancelled') throw new AppError('Перед видаленням скасуйте замовлення.');
            run('DELETE FROM orders WHERE id=? AND client_id=?',[$id,$c['id']]);
        }
    });
}
