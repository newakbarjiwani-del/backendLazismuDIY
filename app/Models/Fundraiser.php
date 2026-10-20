<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Fundraiser extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'code',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public static function idFromCode($code): ?int
    {
        $normalized = strtoupper(trim((string) $code));
        if ($normalized === '') {
            return null;
        }

        $id = static::query()->where('code', $normalized)->value('id');

        return $id ? (int) $id : null;
    }
}
