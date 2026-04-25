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
        Schema::create('system_states', function (Blueprint $table) {
            $table->id();
            $table->boolean('pump_status')->default(false);
            $table->string('mode')->default('auto');
            $table->string('last_label')->nullable();
            $table->timestamp('last_updated')->nullable();
            $table->timestamp('pump_start_ts')->nullable();
            $table->integer('pump_start_minute')->nullable();
            $table->integer('last_watered_minute')->nullable();
            $table->timestamp('last_watered_ts')->nullable();
            $table->float('last_soil_moisture')->nullable();
            $table->float('last_temperature')->nullable();
            $table->boolean('missed_session')->default(false);
            $table->boolean('rain_detected')->default(false);
            $table->integer('rain_score')->default(0);
            $table->integer('rain_confirm_count')->default(0);
            $table->integer('rain_clear_count')->default(0);
            $table->integer('rain_started_minute')->nullable();
            $table->timestamp('last_control_ts')->nullable();
            $table->timestamp('last_sensor_ts')->nullable();
            $table->float('last_sensor_soil')->nullable();
            $table->integer('session_count_today')->default(0);
            $table->date('session_count_date')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('system_states');
    }
};
