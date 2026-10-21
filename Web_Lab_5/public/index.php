<?php
require __DIR__.'/../app/bootstrap.php';
require __DIR__.'/../app/transport.php';
$error=null; $c=null; $services=[]; $token=null; $editing=null;
$page=is_string($_GET['page'] ?? null)?$_GET['page']:'';
try {
    $c=client(); $page=$page ?: ($c?'dashboard':'home');
    $services=run('SELECT * FROM services ORDER BY id')->fetchAll();
    if (!in_array($page,['home','register','login','dashboard','orders','new','profile','confirm','success'],true)) throw new AppError('Сторінку не знайдено.',404);
    if (in_array($page,['dashboard','orders','profile'],true)) $c=require_client();
    if ($page==='new') {
        $c=require_client(true);
        if (isset($_GET['id'])) {
            if (!is_string($_GET['id']) || !preg_match('/^[1-9][0-9]{0,8}$/D',$_GET['id'])) throw new AppError('Замовлення не знайдено.',404);
            $editing=owned_order((int)$_GET['id'],(int)$c['id']);
            if ($editing['status']==='cancelled') throw new AppError('Скасоване замовлення не можна змінити.');
        }
    }
    if ($page==='confirm') $token=token_data(is_string($_GET['token'] ?? null)?$_GET['token']:'');
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        check_csrf(); $action=input('action');
        switch ($action) {
            case 'register': $id=register_client(); dispatch_mail($id); flash('Реєстрацію завершено. Перевірте лист для підтвердження email.'); redirect('/?page=dashboard');
            case 'login': login_client(); redirect('/?page=dashboard');
            case 'logout': $_SESSION=[]; session_regenerate_id(true); redirect('/');
            case 'profile': save_profile(); flash('Контактні дані оновлено.'); redirect('/?page=profile');
            case 'save_order': $id=save_order(); dispatch_mail((int)require_client()['id']); flash('Замовлення '.order_number($id).' збережено. Підтвердьте його через лист.'); redirect('/?page=orders');
            case 'resend': resend_confirmation(); dispatch_mail((int)require_client()['id']); flash('Новий лист підготовлено. Попереднє посилання більше не діє.'); redirect('/?page=dashboard');
            case 'retry_mail': $cc=require_client(); dispatch_mail((int)$cc['id']); flash('Повторну спробу надсилання виконано. Стан листів показано в кабінеті.'); redirect('/?page=dashboard');
            case 'confirm': flash(confirm_token(input('token'))); redirect('/?page=success');
            case 'cancel': case 'delete': change_order($action); flash($action==='delete'?'Замовлення видалено.':'Замовлення скасовано.'); redirect('/?page=orders');
            default: throw new AppError('Невідома дія.',404);
        }
    }
} catch (AppError $ex) { $error=$ex->getMessage(); http_response_code($ex->status); if ($page==='new' && $_SERVER['REQUEST_METHOD']!=='POST') $editing=false; }
catch (Throwable $ex) { $error='Сервіс тимчасово недоступний. Спробуйте пізніше.'; http_response_code(503); error_log('Transport request failed: '.get_class($ex)); }
$message=$_SESSION['flash'] ?? null; unset($_SESSION['flash']);
$titles=['home'=>'Ваша поїздка починається тут','register'=>'Створення акаунта','login'=>'З поверненням','dashboard'=>'Особистий кабінет','orders'=>'Мої замовлення','new'=>'Нове замовлення','profile'=>'Контактні дані','confirm'=>'Підтвердження через email','success'=>'Підтвердження виконано'];
$private=$c && in_array($page,['dashboard','orders','new','profile'],true);
function value(string $key,string $default=''): string { return e(is_string($_POST[$key] ?? null)?$_POST[$key]:$default); }
function action_form(string $action,string $label,?int $id=null,string $class='button secondary',?string $confirm=null): void { ?>
<form method="post" class="inline"<?= $confirm?' data-confirm="'.e($confirm).'"':'' ?>><?=csrf()?>
<input type="hidden" name="action" value="<?=e($action)?>"><?php if ($id): ?><input type="hidden" name="id" value="<?=$id?>"><?php endif ?>
<button class="<?=e($class)?>"><?=e($label)?></button></form>
<?php }
?>
<!doctype html><html lang="uk"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=e($titles[$page] ?? 'Transport')?> — Transport</title><link rel="stylesheet" href="/assets/style.css"><script src="/assets/app.js" defer></script></head>
<body><header class="topbar"><a class="brand" href="/"><span class="brand-icon">↗</span> transport<span class="brand-dot">.</span></a>
<span class="top-tag">Транспортні послуги</span><nav aria-label="Основна навігація">
<?php if ($c): ?><a class="account-link" href="/?page=dashboard"><?=e($c['full_name'])?></a><?php action_form('logout','Вийти',null,'button quiet'); ?>
<?php else: ?><a href="/?page=login" class="button quiet">Увійти</a><a href="/?page=register" class="button primary small">Створити акаунт <span>↗</span></a><?php endif ?>
</nav></header>
<div class="shell <?=$private?'has-sidebar':''?>">
<?php if ($private): ?><aside class="sidebar"><div class="side-heading">ВАШ ПРОСТІР</div>
<a href="/?page=dashboard" class="<?=$page==='dashboard'?'active':''?>"><span>◫</span> Огляд</a>
<a href="/?page=orders" class="<?=in_array($page,['orders','new'])?'active':''?>"><span>▤</span> Мої замовлення</a>
<a href="/?page=profile" class="<?=$page==='profile'?'active':''?>"><span>◎</span> Контактні дані</a>
<div class="sidebar-foot"><span class="live-dot"></span> Підтримка ваших поїздок<br><small>Щодня з 08:00 до 20:00</small></div></aside><?php endif ?>
<main id="main" class="<?=$private?'workspace':'public-main'?>">
<?php if ($message): ?><div role="status" class="notice success"><?=e($message)?></div><?php endif ?>
<?php if ($error): ?><div role="alert" class="notice error"><?=e($error)?></div><?php endif ?>
<?php if ($page==='home'): ?>
<div class="hero"><section><div class="eyebrow"><span class="live-dot"></span> ПРОСТО. ЗРУЧНО. ВЧАСНО.</div>
<h1>Кожна поїздка<br>починається<br><em>з довіри.</em></h1><p class="lead">Містом чи між містами — оформлюйте транспортні замовлення в одному кабінеті. Усі деталі й підтвердження завжди під рукою.</p>
<a href="/?page=register" class="button primary large">Зареєструватися <span>↗</span></a><p class="micro">Реєстрація → підтвердження email → замовлення</p></section>
<div class="route-visual" aria-label="Приклад маршруту Київ — Львів"><div class="route-top"><span>ВАШ НАСТУПНИЙ МАРШРУТ</span><span class="badge confirmed">Разом у дорозі</span></div><div class="route-map"><span class="map-dot start"></span><span class="map-dot end"></span><div class="map-label kyiv">Київ<small>Відправлення</small></div><div class="map-label lviv">Львів<small>Прибуття</small></div><div class="route-car">↗</div></div><div class="route-bottom"><span>Більше планів.<br><strong>Менше турбот.</strong></span><span class="round-arrow">↗</span></div></div></div>
<section class="services"><div class="section-line"><h2>Транспорт під ваші плани</h2><span>01 — 03</span></div><div class="service-grid"><?php foreach ($services as $i=>$s): ?><article class="service-card"><span class="service-index">0<?=$i+1?> /</span><h3><?=e($s['name'])?></h3><p><?=e($s['description'])?></p><div class="service-bottom"><span>До <?=$s['max_passengers']?> пасажирів</span><strong>від <?=number_format((float)$s['base_price'],0,'',' ')?> ₴</strong></div></article><?php endforeach ?></div><p class="micro">Орієнтовні тарифи. Остаточну вартість маршруту погоджує диспетчер.</p></section>
<?php elseif (in_array($page,['register','login'],true)): ?>
<div class="auth-layout"><section class="auth-intro"><div class="eyebrow">ОСОБИСТИЙ КАБІНЕТ</div><h1>Ваші маршрути.<br><em>Ваш простір.</em></h1><p>Реєструйтеся один раз, плануйте скільки завгодно. Підтверджуйте поїздки листом і відстежуйте замовлення онлайн.</p><ol class="steps"><li><span>01</span> Створіть акаунт</li><li><span>02</span> Підтвердьте email</li><li><span>03</span> Оформіть поїздку</li></ol></section>
<section class="panel auth-panel"><div class="eyebrow"><?=$page==='register'?'ПЕРШИЙ КРОК':'РАДІ ВАС БАЧИТИ'?></div><h2><?=e($titles[$page])?></h2><p class="muted"><?=$page==='register'?'Заповніть контактні дані — і почнемо.':'Увійдіть, щоб продовжити планування.'?></p>
<form method="post" class="stack"><?=csrf()?><input type="hidden" name="action" value="<?=$page?>">
<?php if ($page==='register'): ?><label>Ім’я та прізвище<input name="full_name" autocomplete="name" minlength="3" maxlength="120" placeholder="Іван Петренко" value="<?=value('full_name')?>" required></label><?php endif ?>
<label>Email<input type="email" name="email" autocomplete="email" maxlength="254" placeholder="name@example.com" value="<?=value('email')?>" required></label>
<?php if ($page==='register'): ?><label>Телефон<input name="phone" type="tel" autocomplete="tel" minlength="10" maxlength="20" placeholder="+380 67 123 45 67" value="<?=value('phone')?>" required></label><?php endif ?>
<label>Пароль<input name="password" type="password" autocomplete="<?=$page==='register'?'new-password':'current-password'?>" minlength="<?=$page==='register'?'10':'1'?>" maxlength="72" required></label>
<?php if ($page==='register'): ?><small class="hint">Від 10 символів; не більше 72 байтів.</small><label>Повторіть пароль<input name="password_confirmation" type="password" autocomplete="new-password" minlength="10" maxlength="72" required></label>
<label class="check"><input type="checkbox" name="consent" value="1" required <?=isset($_POST['consent'])?'checked':''?>><span>Погоджуюся на обробку контактних даних для оформлення транспортних замовлень.</span></label><?php endif ?>
<button class="button primary full"><?=$page==='register'?'Створити акаунт':'Увійти до кабінету'?> <span>↗</span></button></form>
<p class="auth-switch"><?=$page==='register'?'Уже маєте акаунт?':'Ще немає акаунта?'?> <a href="/?page=<?=$page==='register'?'login':'register'?>"><?=$page==='register'?'Увійти':'Зареєструватися'?></a></p></section></div>
<?php elseif ($private): ?>
<div class="page-heading"><div><div class="eyebrow">TRANSPORT / ОСОБИСТИЙ КАБІНЕТ</div><h1><?=e($titles[$page])?></h1><p class="muted"><?=$page==='dashboard'?'Усе для вашої наступної поїздки — в одному місці.':($page==='orders'?'Плануйте, перевіряйте та керуйте своїми маршрутами.':($page==='profile'?'Актуальні дані допоможуть нам бути на зв’язку.':'Розкажіть, куди та коли ви плануєте поїздку.'))?></p></div>
<?php if ($c['email_verified_at'] && in_array($page,['dashboard','orders'])): ?><a class="button primary" href="/?page=new">+ Нове замовлення</a><?php endif ?></div>
<?php if (!$c['email_verified_at']): ?><section class="verification panel"><div class="email-icon">✉</div><div><h2>Підтвердьте вашу email адресу</h2><p>Лист надіслано на <strong><?=e($c['email'])?></strong>. Відкрийте посилання та натисніть «Підтвердити email». Після цього можна оформлювати замовлення.</p><div class="actions"><?php action_form('resend','Надіслати лист повторно'); ?><?php if (getenv('DEMO_MAILBOX')==='1'): ?><a href="http://127.0.0.1:8026" class="text-link" target="_blank" rel="noreferrer">Демо скринька ↗</a><?php endif ?></div><small class="hint">Посилання діє 24 години. Повторний лист — через 60 секунд.</small></div></section><?php endif ?>
<?php $pending=(int)run("SELECT COUNT(*) FROM mail_jobs WHERE client_id=? AND status='pending'",[$c['id']])->fetchColumn(); if ($pending): ?><div class="notice warning">У черзі на надсилання: <?=$pending?>. Лист ще не прийнято SMTP сервером.<?php action_form('retry_mail','Повторити надсилання',null,'button quiet'); ?></div><?php endif ?>
<?php if ($page==='dashboard'): $stats=run("SELECT COUNT(*) total,COALESCE(SUM(status='confirmed'),0) confirmed,COALESCE(SUM(status='awaiting_email'),0) waiting FROM orders WHERE client_id=?",[$c['id']])->fetch(); ?>
<div class="stats"><article><span>Усього замовлень</span><strong><?=$stats['total']?></strong><small>Ваші маршрути</small></article><article><span>Підтверджено</span><strong><?=$stats['confirmed']?></strong><small>Готові до планування</small></article><article><span>Очікують email</span><strong><?=$stats['waiting']?></strong><small>Потрібна ваша дія</small></article><article class="account-stat"><span>Стан акаунта</span><strong class="status-word"><?=$c['email_verified_at']?'Активний':'Перевірка'?></strong><small><?=$c['email_verified_at']?'Email підтверджено':'Перевірте пошту'?></small></article></div>
<section class="panel"><div class="section-line"><h2>Останні замовлення</h2><a href="/?page=orders" class="text-link">Усі замовлення ↗</a></div>
<?php $recent=run('SELECT o.*,s.name service FROM orders o JOIN services s ON s.id=o.service_id WHERE client_id=? ORDER BY o.id DESC LIMIT 5',[$c['id']])->fetchAll(); if (!$recent): ?><div class="empty"><span>↗</span><h3>Попереду нові маршрути</h3><p>Ваше перше транспортне замовлення з’явиться тут.</p><?php if ($c['email_verified_at']): ?><a href="/?page=new" class="button secondary">Спланувати поїздку</a><?php endif ?></div><?php else: foreach ($recent as $o): ?><div class="recent-row"><div><strong><?=e($o['origin'])?> <span class="muted">→</span> <?=e($o['destination'])?></strong><small><?=order_number((int)$o['id'])?> · <?=e($o['service'])?></small></div><span><?=local_date($o['departure_at'])?></span><span class="badge <?=e($o['status'])?>"><?=status_label($o['status'])?></span></div><?php endforeach; endif ?></section>
<div class="guide"><div class="guide-no">i</div><div><h3>Один лист — і поїздку підтверджено</h3><p>Після оформлення замовлення відкрийте лист і підтвердьте деталі. Після редагування надійде нове посилання. Час виїзду вказано за Києвом.</p></div></div>
<?php elseif ($page==='orders'): ?>
<section class="panel"><form method="get" class="filters"><input type="hidden" name="page" value="orders"><label class="search-label">Пошук маршруту<input name="q" maxlength="100" placeholder="Місто або адреса…" value="<?=e(is_string($_GET['q'] ?? null)?$_GET['q']:'')?>"></label><label>Статус<select name="status"><?php $filter=is_string($_GET['status'] ?? null)?$_GET['status']:''; foreach ([''=>'Усі статуси','awaiting_email'=>'Очікує email','confirmed'=>'Підтверджено','cancelled'=>'Скасовано'] as $key=>$label): ?><option value="<?=e($key)?>" <?=$filter===$key?'selected':''?>><?=e($label)?></option><?php endforeach ?></select></label><button class="button secondary">Знайти</button><a class="text-link" href="/?page=orders">Скинути</a></form>
<?php $values=[$c['id']]; $where='o.client_id=?';
$q=is_string($_GET['q'] ?? null)?substr(trim($_GET['q']),0,100):'';
if ($q!=='') { $where.=' AND (o.origin LIKE ? OR o.destination LIKE ?)'; $values[]='%'.$q.'%'; $values[]='%'.$q.'%'; }
if (in_array($filter,['awaiting_email','confirmed','cancelled'],true)) { $where.=' AND o.status=?'; $values[]=$filter; }
$count=(int)run('SELECT COUNT(*) FROM orders o WHERE '.$where,$values)->fetchColumn();
$current=max(1,(int)(is_scalar($_GET['p'] ?? null)?$_GET['p']:1)); $current=min($current,max(1,(int)ceil($count/10))); $offset=($current-1)*10;
$orders=run('SELECT o.*,s.name service FROM orders o JOIN services s ON s.id=o.service_id WHERE '.$where.' ORDER BY o.id DESC LIMIT 10 OFFSET '.$offset,$values)->fetchAll(); ?>
<div class="section-line"><h2>Історія поїздок</h2><span class="muted">Знайдено: <?=$count?></span></div>
<?php if (!$orders): ?><div class="empty"><span>⌕</span><h3>Замовлень поки немає</h3><p>Створіть нове замовлення або змініть параметри пошуку.</p></div><?php else: ?><div class="table-scroll"><table><thead><tr><th>Замовлення / маршрут</th><th>Послуга / пасажири</th><th>Виїзд · Київ</th><th>Статус</th><th>Дії</th></tr></thead><tbody><?php foreach ($orders as $o): ?><tr><td><small><?=order_number((int)$o['id'])?></small><strong><?=e($o['origin'])?></strong><span class="route-to">→ <?=e($o['destination'])?></span><?php if ($o['notes']): ?><small><?=e($o['notes'])?></small><?php endif ?></td><td><?=e($o['service'])?><small><?=$o['passengers']?> пас.</small></td><td class="date-cell"><?=local_date($o['departure_at'])?></td><td><span class="badge <?=e($o['status'])?>"><?=status_label($o['status'])?></span></td><td><div class="row-actions"><?php if ($o['status']!=='cancelled'): ?><a class="text-link" href="/?page=new&id=<?=$o['id']?>">Редагувати</a><?php if ($o['status']==='awaiting_email') action_form('resend','Повторити лист',(int)$o['id'],'link-button'); action_form('cancel','Скасувати',(int)$o['id'],'link-button danger','Скасувати це замовлення?'); else: action_form('delete','Видалити',(int)$o['id'],'link-button danger','Остаточно видалити скасоване замовлення?'); endif ?></div></td></tr><?php endforeach ?></tbody></table></div><?php endif ?>
<?php if ($count>10): ?><nav class="pagination" aria-label="Сторінки"><?php for ($i=1;$i<=ceil($count/10);$i++): ?><a class="button <?=$current===$i?'primary':'quiet'?>" href="/?<?=e(http_build_query(['page'=>'orders','q'=>$q,'status'=>$filter,'p'=>$i]))?>"><?=$i?></a><?php endfor ?></nav><?php endif ?></section>
<?php elseif ($page==='new' && $c['email_verified_at']):
if ($editing!==false):
$defaultDate=$editing?(new DateTimeImmutable($editing['departure_at'],new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Europe/Kyiv'))->format('Y-m-d\TH:i'):''; ?>
<div class="order-layout"><section class="panel"><div class="section-line"><h2><?=$editing?'Редагування '.order_number((int)$editing['id']):'Деталі вашої поїздки'?></h2><span class="muted">01 / 02</span></div>
<form method="post" class="stack" id="order-form"><?=csrf()?><input type="hidden" name="action" value="save_order"><input type="hidden" name="id" value="<?=e($editing['id'] ?? '')?>">
<label>Транспортна послуга<select name="service_id" id="service" required><option value="">Оберіть послугу</option><?php foreach ($services as $s): ?><option value="<?=$s['id']?>" data-max="<?=$s['max_passengers']?>" <?=value('service_id',(string)($editing['service_id'] ?? ''))===(string)$s['id']?'selected':''?>><?=e($s['name'])?> · до <?=$s['max_passengers']?> пас.</option><?php endforeach ?></select></label>
<div class="form-grid"><label>Звідки<input name="origin" placeholder="Місто, адреса відправлення" minlength="3" maxlength="150" value="<?=value('origin',$editing['origin'] ?? '')?>" required></label><label>Куди<input name="destination" placeholder="Місто, адреса призначення" minlength="3" maxlength="150" value="<?=value('destination',$editing['destination'] ?? '')?>" required></label>
<label>Дата та час виїзду<input type="datetime-local" name="departure_at" value="<?=value('departure_at',$defaultDate)?>" required><small class="hint">За Києвом; щонайменше за 1 годину.</small></label><label>Кількість пасажирів<input type="number" name="passengers" id="passengers" min="1" max="50" step="1" value="<?=value('passengers',(string)($editing['passengers'] ?? '1'))?>" required></label></div>
<label>Коментар <span class="muted">· необов’язково</span><textarea name="notes" maxlength="500" rows="3" placeholder="Багаж, дитяче крісло, особливості маршруту…"><?=value('notes',$editing['notes'] ?? '')?></textarea></label>
<div class="form-footer"><a class="button quiet" href="/?page=orders">Скасувати</a><button class="button primary">Зберегти й надіслати лист <span>↗</span></button></div></form></section>
<aside class="order-summary"><div class="eyebrow">НАСТУПНИЙ КРОК</div><div class="big-mail">✉</div><h2>Перевірте пошту</h2><p>Після збереження надішлемо лист на <strong><?=e($c['email'])?></strong>. Перевірте маршрут і підтвердьте замовлення.</p><div class="summary-note">Посилання діє 1 годину.<br>Редагування потребує нового підтвердження.</div><p class="micro">Вартість та можливість виконання поїздки погоджує диспетчер. Email підтверджує вашу заявку.</p></aside></div>
<?php endif; elseif ($page==='profile'): ?>
<section class="panel profile-panel"><div class="section-line"><h2>Ваші контакти</h2><span class="badge <?=$c['email_verified_at']?'confirmed':'awaiting_email'?>"><?=$c['email_verified_at']?'Email підтверджено':'Очікує email'?></span></div><form method="post" class="stack"><?=csrf()?><input type="hidden" name="action" value="profile"><label>Ім’я та прізвище<input name="full_name" autocomplete="name" minlength="3" maxlength="120" value="<?=value('full_name',$c['full_name'])?>" required></label><label>Email<input type="email" value="<?=e($c['email'])?>" disabled><small class="hint">Email є ідентифікатором акаунта.</small></label><label>Телефон<input type="tel" name="phone" autocomplete="tel" minlength="10" maxlength="20" value="<?=value('phone',$c['phone'])?>" required></label><div class="form-footer"><button class="button primary">Зберегти зміни</button></div></form></section>
<?php endif ?>
<?php elseif ($page==='confirm' && $token && !$error): ?><section class="panel confirmation"><span class="confirmation-icon">✉</span><div class="eyebrow">ЩЕ ОДИН КРОК</div><h1><?=$token['kind']==='verify'?'Підтвердьте email':'Підтвердьте замовлення'?></h1>
<?php if ($token['order_id']): $o=run('SELECT * FROM orders WHERE id=?',[$token['order_id']])->fetch(); ?><p class="confirm-route"><?=e($o['origin'])?> → <?=e($o['destination'])?></p><p><?=order_number((int)$o['id'])?> · <?=local_date($o['departure_at'])?> · <?=$o['passengers']?> пас.</p><?php else: ?><p>Ця дія підтвердить вашу email адресу в Transport.</p><?php endif ?><form method="post"><?=csrf()?><input type="hidden" name="action" value="confirm"><input type="hidden" name="token" value="<?=e($_GET['token'])?>"><button class="button primary large"><?=$token['kind']==='verify'?'Підтвердити email':'Підтвердити замовлення'?> <span>✓</span></button></form><small class="hint">Відкриття посилання саме по собі не змінює дані.</small></section>
<?php elseif ($page==='success'): ?><section class="panel confirmation"><span class="confirmation-icon">✓</span><h1>Готово. Дякуємо!</h1><p>Результат підтвердження показано вище. Деталі доступні у вашому кабінеті.</p><a class="button primary" href="/?page=<?=$c?'dashboard':'login'?>"><?=$c?'Перейти до кабінету':'Увійти до кабінету'?> ↗</a></section>
<?php elseif (!$error): ?><section class="panel confirmation"><h1>Увійдіть до кабінету</h1><a href="/?page=login" class="button primary">Увійти</a></section><?php endif ?>
</main></div><footer class="site-footer"><span>© <?=date('Y')?> Transport</span><span>Кожен маршрут має значення.</span><?php if (getenv('DEMO_MAILBOX')==='1'): ?><a href="http://127.0.0.1:8026" target="_blank" rel="noreferrer">Демо пошта ↗</a><?php endif ?></footer></body></html>
