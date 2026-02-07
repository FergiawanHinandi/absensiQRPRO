<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
use App\Models\School;
use App\Models\User;
$rid = substr(md5(uniqid('', true)), 0, 6);
$school = new School([
    'name' => 'LT One '.$rid,
    'address' => 'LT',
    'is_active' => true,
    'settings' => [],
]);
$school->npsn = (string) rand(10000000, 99999999);
$school->school_level = 'SMA';
$school->save();
$user = User::create([
    'school_id' => $school->id,
    'username' => "lt_one_{$rid}",
    'name' => "LT One {$rid}",
    'email' => "lt_one_{$rid}@example.test",
    'password' => 'password',
    'role_type' => 'teacher',
    'is_active' => true,
    'device_id' => "lt-one-{$rid}",
]);
$token = $user->createToken('loadtest', ['*'])->plainTextToken;
$data = [
    'school_id' => $school->id,
    'user_id' => $user->id,
    'token' => $token,
    'device_id' => $user->device_id,
];
file_put_contents(__DIR__ . '/../token_one.json', json_encode($data, JSON_PRETTY_PRINT));
echo "TOKEN_READY" . PHP_EOL;
