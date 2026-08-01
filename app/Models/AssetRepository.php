<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class AssetRepository extends Pivot
{
    protected $table = 'asset_repository';

    public $incrementing = false;
}
