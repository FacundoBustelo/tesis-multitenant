<?php

namespace App\Contracts;

use App\Models\Record;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface RecordRepository
{
    public function paginate(int $perPage): LengthAwarePaginator;

    public function find(int $id): Record;

    public function create(array $data): Record;

    public function update(Record $record, array $data): Record;

    public function delete(Record $record): void;
}
