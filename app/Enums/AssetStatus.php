<?php

namespace App\Enums;

enum AssetStatus: string
{
    case Active = 'active';
    case Paused = 'paused';
    case Archived = 'archived';
}
