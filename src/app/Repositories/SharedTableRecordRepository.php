<?php

namespace App\Repositories;

use App\Contracts\RecordRepository;
use App\Models\Record;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class SharedTableRecordRepository implements RecordRepository
{
    public function paginate(int $perPage): LengthAwarePaginator
    {
        return Record::query()->orderBy('id')->paginate($perPage);
    }

    public function find(int $id): Record
    {
        return Record::query()->findOrFail($id);
    }

    public function create(array $data): Record
    {
        return Record::create($data);
    }

    public function update(Record $record, array $data): Record
    {
        $record->update($data);

        return $record->refresh();
    }

    public function delete(Record $record): void
    {
        $record->delete();
    }
}
