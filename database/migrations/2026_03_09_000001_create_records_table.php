<?php

use App\Enums\RecordType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('records', function (Blueprint $table) {
            $table->id();
            $table->string('record_id')->unique();
            $table->dateTime('time');
            $table->string('source_id');
            $table->string('destination_id');
            $table->enum('type', array_column(RecordType::cases(), 'value'));
            $table->decimal('value', 20, 4);
            $table->string('unit');
            $table->string('reference');
            $table->timestamps();

            $table->index(['destination_id', 'time'], 'ix_dest_time');
            $table->index(['destination_id', 'type'], 'ix_dest_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('records');
    }
};
