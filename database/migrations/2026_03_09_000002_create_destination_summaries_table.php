<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('destination_summaries', function (Blueprint $table) {
            $table->id();
            $table->string('destination_id');
            $table->string('reference');
            $table->unsignedBigInteger('record_count')->default(0);
            $table->decimal('total_value', 20, 4)->default(0);
            $table->timestamps();

            $table->unique(['destination_id', 'reference']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('destination_summaries');
    }
};
