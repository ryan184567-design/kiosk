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
        'status',
    ];

    public function doorplate(): BelongsTo
    {
        return $this->belongsTo(Doorplate::class, 'doorplate_id')->withTrashed();
    }
}
