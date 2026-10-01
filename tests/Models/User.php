<?php

namespace Lunargraphql\Tests\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Lunar\Core\Contracts\LunarUser as LunarUserContract;
use Lunargraphql\Traits\LunarUser;

class User extends Authenticatable implements LunarUserContract
{
    use HasApiTokens, HasFactory, LunarUser, Notifiable;

    protected $guarded = [];

    protected $hidden = [
        'password',
        'remember_token',
    ];
}
