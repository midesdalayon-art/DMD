<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$rows = App\Models\Accommodation::query()
    ->where('name', 'like', 'Room 1%')
    ->orderBy('id')
    ->get(['id', 'name', 'slug', 'type', 'status', 'housekeeping_status', 'created_at', 'updated_at']);

foreach ($rows as $row) {
    echo json_encode($row->toArray(), JSON_UNESCAPED_SLASHES).PHP_EOL;
}

echo 'COUNT='.App\Models\Accommodation::count().PHP_EOL;
