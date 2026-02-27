<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // FK players.country_id -> countries.id już istnieje, więc nic nie robimy.
    }

    public function down(): void
    {
        // celowo puste
    }


};
