<?php

namespace App\Enums;

enum UserRole: string
{
    case USER = 'user';
    case SCOUT_FOUNDER = 'scout_founder';
    case ADMIN = 'admin';
}
