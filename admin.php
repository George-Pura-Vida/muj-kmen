<?php
declare(strict_types=1);
require __DIR__.'/auth/bootstrap.php';
auth_boot();
auth_require();
auth_redirect('profile','Můj kmen je pouze pro jediného správce. Přidávání dalších uživatelů není povoleno.');
