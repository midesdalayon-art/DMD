<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

App\Models\Reservation::whereIn('id', [7,8])->delete();
App\Models\Accommodation::whereIn('id', [17,18,19,20,21,22])->delete();

echo 'accommodations='.App\Models\Accommodation::where('name','like','Room 1%')->orderBy('id')->pluck('id')->implode(',').PHP_EOL;
echo 'reservations='.App\Models\Reservation::whereIn('accommodation_id', [17,18,19,20,21,22])->count().PHP_EOL;
