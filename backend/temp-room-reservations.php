<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$rows = App\Models\Reservation::query()
    ->whereIn('accommodation_id', [17,18,19,20,21,22])
    ->orderBy('id')
    ->get(['id','accommodation_id','check_in','check_out','check_in_at','check_out_at','status','booking_reference','created_at']);
foreach ($rows as $row) {
    echo json_encode($row->toArray(), JSON_UNESCAPED_SLASHES).PHP_EOL;
}
echo 'COUNT='.App\Models\Reservation::whereIn('accommodation_id', [17,18,19,20,21,22])->count().PHP_EOL;
