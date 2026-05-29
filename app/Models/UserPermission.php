<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserPermission extends Model
{
    protected $fillable = ['user_id', 'permission_name', 'granted'];
    protected $casts    = ['granted' => 'boolean'];
}
