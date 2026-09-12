<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('accommodations')->whereIn('type', ['rooms', 'room'])->update(['type' => 'room']);
        DB::table('accommodations')->whereIn('type', ['cottages', 'cottage'])->update(['type' => 'cottage']);
        DB::table('accommodations')->whereIn('type', ['family', 'family_room'])->update(['type' => 'room']);
        DB::table('accommodations')->whereIn('type', ['function-hall', 'function hall', 'function_halls'])->update(['type' => 'function_hall']);
    }

    public function down(): void
    {
        DB::table('accommodations')->where('type', 'room')->update(['type' => 'rooms']);
        DB::table('accommodations')->where('type', 'cottage')->update(['type' => 'cottages']);
    }
};
