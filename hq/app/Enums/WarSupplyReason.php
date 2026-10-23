<?php

namespace App\Enums;

/** Why a Kolguyev supply balance changed. */
enum WarSupplyReason: string
{
    case Grant = 'grant';
    case Income = 'income';
    case Recruit = 'recruit';
    case Train = 'train';
    case Refund = 'refund';
    case Support = 'support';
    case Vehicle = 'vehicle';
    case Plunder = 'plunder';
    case Raided = 'raided';
    case Objective = 'objective';
}
