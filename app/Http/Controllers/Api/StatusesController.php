<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Http\Resources\StatusResource;
use App\Models\Status;

class StatusesController extends Controller
{

    public function index()
    {
        $statuses = Status::all();
        return StatusResource::collection($statuses);
    }


    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255|unique:statuses,name',
        ]);

        $status = Status::create([
            'name' => $request->input('name'),
        ]);

        return new StatusResource($status);
    }


    public function show(string $id)
    {
        $status = Status::findorFail($id);
        return new StatusResource($status);
    }

    
    public function update(Request $request, string $id)
    {
        $status = Status::findOrFail($id);

        $request->validate([
            'name' => 'sometimes|required|string|max:255|unique:statuses,name,' . $status->id,
        ]);

        $updatedBy = $request->input('updated_by');

        $data = [];
        if ($request->has('name')) {
            $data['name'] = $request->input('name');
        }

        if (!empty($data)) {
            $status->update($data);
        }

        return new StatusResource($status);
    }


    public function destroy(string $id)
    {
        try {
            Status::findOrFail($id)->delete();
            return response()->json(['message' => 'Deleted Successfully'], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Status cannot be deleted'], 400);
        }
    }
}
