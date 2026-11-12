<?php

namespace App\Enums;

// Future application requests are untrusted intent, separate from User::role.
enum RequestedAccessRole: string
{
    case Admin = 'admin';
    case Client = 'client';
}
