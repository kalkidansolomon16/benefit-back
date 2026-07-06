<?php
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$u = App\Models\User::where('email','jhon@gmail.com')->first();
if (!$u) { echo "User not found\n"; exit; }
echo "role: " . $u->role . "\n";
echo "must_reset_password: " . ($u->must_reset_password ? 'true' : 'false') . "\n";
echo "must_change_password: " . ($u->must_change_password ? 'true' : 'false') . "\n";
echo "is_active: " . ($u->is_active ? 'true' : 'false') . "\n";
