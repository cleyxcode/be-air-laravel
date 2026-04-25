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
        Schema::create('sensor_readings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->timestamp('timestamp')->useCurrent();
            $table->float('soil_moisture');
            $table->float('temperature');
            $table->float('air_humidity');
            $table->string('label');
            $table->float('confidence');
            $table->boolean('needs_watering');
            $table->string('description')->nullable();
            $table->json('probabilities')->nullable();
            $table->boolean('pump_status');
            $table->string('mode');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sensor_readings');
    }
};
