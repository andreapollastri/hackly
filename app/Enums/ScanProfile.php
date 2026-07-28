<?php

namespace App\Enums;

enum ScanProfile: string
{
    case Quick = 'quick';
    case Standard = 'standard';
    case Deep = 'deep';
}
