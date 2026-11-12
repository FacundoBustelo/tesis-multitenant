<?php

namespace App\Policies;

use App\Models\Record;
use App\Models\Staff;
use App\Models\User;

class RecordPolicy
{
    public function viewAny(User|Staff $actor): bool
    {
        return true;
    }

    public function view(User|Staff $actor, Record $record): bool
    {
        return (int) $actor->tenant_id === (int) $record->tenant_id;
    }

    public function create(User|Staff $actor): bool
    {
        return $actor instanceof Staff;
    }

    public function update(User|Staff $actor, Record $record): bool
    {
        return $actor instanceof Staff && $this->view($actor, $record);
    }

    public function delete(User|Staff $actor, Record $record): bool
    {
        return $this->update($actor, $record);
    }
}
