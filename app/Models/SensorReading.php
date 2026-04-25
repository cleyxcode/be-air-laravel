<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SensorReading extends Model
{
    use HasFactory;

    public $incrementing = false;
    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'timestamp' => 'datetime',
        'needs_watering' => 'boolean',
        'pump_status' => 'boolean',
        'probabilities' => 'array',
    ];
}
