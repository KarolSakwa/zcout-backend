<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE countries ALTER COLUMN iso2 TYPE varchar(8)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE countries ALTER COLUMN iso2 TYPE varchar(2)');
    }
};
