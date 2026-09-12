<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$ids = [17,18,19,20,21,22];
$deleted = App\Models\Accommodation::whereIn('id', $ids)->delete();
echo 'deleted='.$deleted.PHP_EOL;
echo 'remaining='.App\Models\Accommodation::where('name', 'like', 'Room 1%')->orderBy('id')->pluck('id')->implode(',').PHP_EOL;
