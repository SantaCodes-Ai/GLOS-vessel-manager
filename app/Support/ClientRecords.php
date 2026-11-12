<?php

namespace App\Support;

use App\Models\Certificate;
use App\Models\User;
use App\Models\Vessel;
use Illuminate\Database\Eloquent\Builder;

class ClientRecords
{
    public function vessels(User $client): Builder
    {
        return Vessel::whereHas('users', fn ($query) => $query->where('users.id', $client->id));
    }

    public function certificates(User $client): Builder
    {
        return Certificate::whereHas('vessel.users', fn ($query) => $query->where('users.id', $client->id));
    }
}
