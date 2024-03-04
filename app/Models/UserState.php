<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserState extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'state',
        'current_question_id',
        'processing_message_id',
        'utm_source',
    ];

    public function user()
    {
        return $this->belongsTo(User::class,'user_id', 'id');
    }
}
