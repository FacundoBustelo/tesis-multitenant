<?php

namespace App\Http\Controllers;

use App\Contracts\RecordRepository;
use App\Http\Requests\RecordRequest;
use App\Http\Resources\RecordResource;
use App\Models\Record;
use App\Services\AuditLogger;
use App\Services\TenantTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class RecordController extends Controller
{
    public function __construct(private RecordRepository $records, private AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Record::class);
        $data = $request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);

        return TenantTransaction::run(function () use ($request, $data) {
            $records = $this->records->paginate($data['per_page'] ?? 20);
            $this->audit->write($request->user(), 'record.list', 'record');

            return RecordResource::collection($records)->response();
        });
    }

    public function show(Request $request, int $record): JsonResponse
    {
        return TenantTransaction::run(function () use ($request, $record) {
            $model = $this->records->find($record);
            Gate::authorize('view', $model);
            $this->audit->write($request->user(), 'record.read', 'record', $model->id);

            return response()->json(['data' => new RecordResource($model)]);
        });
    }

    public function store(RecordRequest $request): JsonResponse
    {
        Gate::authorize('create', Record::class);
        $data = $request->validated();

        return TenantTransaction::run(function () use ($request, $data) {
            $record = $this->records->create($data);
            $this->audit->write($request->user(), 'record.create', 'record', $record->id);

            return response()->json(['data' => new RecordResource($record)], 201);
        });
    }

    public function update(RecordRequest $request, int $record): JsonResponse
    {
        return TenantTransaction::run(function () use ($request, $record) {
            $model = $this->records->find($record);
            Gate::authorize('update', $model);
            $model = $this->records->update($model, $request->validated());
            $this->audit->write($request->user(), 'record.update', 'record', $model->id);

            return response()->json(['data' => new RecordResource($model)]);
        });
    }

    public function destroy(Request $request, int $record): JsonResponse
    {
        return TenantTransaction::run(function () use ($request, $record) {
            $model = $this->records->find($record);
            Gate::authorize('delete', $model);
            $this->records->delete($model);
            $this->audit->write($request->user(), 'record.delete', 'record', $model->id);

            return response()->json(null, 204);
        });
    }
}
