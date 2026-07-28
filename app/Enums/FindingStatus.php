<?php

namespace App\Enums;

enum FindingStatus: string
{
    case Open = 'open';
    case Ack = 'ack';
    case Fixed = 'fixed';
    case FalsePositive = 'false_positive';
}
