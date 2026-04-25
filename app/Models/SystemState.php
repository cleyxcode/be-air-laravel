<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SystemState extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'pump_status' => 'boolean',
        'missed_session' => 'boolean',
        'rain_detected' => 'boolean',
        'last_updated' => 'datetime',
        'pump_start_ts' => 'datetime',
        'last_watered_ts' => 'datetime',
        'last_control_ts' => 'datetime',
        'last_sensor_ts' => 'datetime',
    ];
}
