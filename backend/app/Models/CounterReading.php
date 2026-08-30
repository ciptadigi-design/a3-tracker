<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class CounterReading extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['account_id', 'machine_id', 'counter_type_id', 'reading_value', 'observed_at', 'shift_code', 'operator_person_id', 'operator_name_snapshot', 'entered_by', 'source', 'previous_reading_id', 'status', 'notes', 'client_request_id'];

    protected $casts = ['reading_value' => 'decimal:4', 'observed_at' => 'datetime', 'created_at' => 'datetime', 'updated_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(fn ($m) => $m->id ??= Str::uuid());
    }

    public function machine()
    {
        return $this->belongsTo(Machine::class);
    }

    public function operator()
    {
        return $this->belongsTo(OperationalPerson::class, 'operator_person_id');
    }

    public function previous()
    {
        return $this->belongsTo(self::class, 'previous_reading_id');
    }
}
