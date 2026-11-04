<?php
require __DIR__.'/bootstrap.php';
if (!$mkUser && ($_GET['demo']??'')!=='1') auth_redirect('login');
readfile(__DIR__.'/app.html');
