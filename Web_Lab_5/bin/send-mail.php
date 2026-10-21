<?php
require __DIR__.'/../app/bootstrap.php';
require __DIR__.'/../app/transport.php';
$sent=dispatch_mail(null,100);
$pending=(int)run("SELECT COUNT(*) FROM mail_jobs WHERE status='pending'")->fetchColumn();
echo "SMTP accepted: $sent; pending: $pending\n";
exit($pending?1:0);
