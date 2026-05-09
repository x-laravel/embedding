<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('embedding.database.connection');
    }

    public function up(): void
    {
        Schema::create(config('embedding.database.table'), function (Blueprint $table) {
            $table->id();
            $table->morphs('embeddable');
            $table->string('slot', 64)->default('default');
            $table->json('vector');
            $table->timestamps();

            $table->unique(['embeddable_type', 'embeddable_id', 'slot']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('embedding.database.table'));
    }
};
