<?php

/* *****************************************
File: User.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 子站使用者帳號 Model，登入、端口權限（見 UserPortalAccess）、身份別（見 IdentityType）皆以此表為核心，見 README 7 節。
***************************************** */

namespace App\Api\User\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Support\Identity\Models\IdentityType;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    // 使用者屬於子站自己的資料庫，一律走動態註冊的 site_current 連線，見 README 6.4。
    protected $connection = 'site_current';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'identity_type_id',
        'username',
        'name',
        'email',
        'password',
    ];

    public function identityType()
    {
        return $this->belongsTo(IdentityType::class);
    }

    public function portalAccess()
    {
        return $this->hasMany(UserPortalAccess::class);
    }

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
