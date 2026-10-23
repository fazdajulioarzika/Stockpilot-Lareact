<?php

namespace App\Enums;

enum MovementType: string
{
    case Receipt = 'receipt';
    case Issue = 'issue';
    case TransferOut = 'transfer_out';
    case TransferIn = 'transfer_in';
    case Adjustment = 'adjustment';
    case Reversal = 'reversal';
}
