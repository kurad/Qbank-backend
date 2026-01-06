<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Group extends Model
{
    use HasFactory;

    protected $fillable = [
        'group_name',
        'class_code',
        'created_by',
    ];

    public function students()
    {
        return $this->belongsToMany(User::class, 'group_students','group_id','student_id');
    }
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
