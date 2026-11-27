<?php

namespace App\Api\DataSetting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Doorplate extends Model
{
    use SoftDeletes;

    protected $connection = 'site_current';
    protected $table = 'doorplates';

    protected $fillable = [
        'id',
        'doorplate',
        'floor',
        'household',
        'company_name',
        'approval_required',
        'status',
    ];

    protected $casts = [
        // 訪客模組公司端口：員工新增預約是否需要審核（false＝自動核准）
        'approval_required' => 'boolean',
    ];

    public function doorplate(): BelongsTo
    {
        return $this->belongsTo(Doorplate::class, 'doorplate_id')->withTrashed();
    }
}
