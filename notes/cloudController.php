
// for cloud

// public function syncFromLocal(Request $request)
// {

//     Log::info($request->all());

//     $request->validate([
//         'name' => 'required|string|max:255',
//         'phone' => 'nullable|string|max:50',
//         'address' => 'nullable|string|max:255',
//         'status_id' => 'required|exists:statuses,id',
//         'created_by' => 'required|exists:users,id',
//         'updated_by' => 'nullable|exists:users,id'
//     ]);

//     $supplier = Supplier::create([
//         'id' => $request->id,
//         'name' => $request->name,
//         'phone' => $request->phone,
//         'address' => $request->address,
//         'status_id' => $request->status_id,
//         'created_by' => $request->created_by,
//         'updated_by' => $request->updated_by ?? $request->created_by
//     ]);

//     return new SupplierResource($supplier->fresh(['status', 'createdBy', 'updatedBy']));
// }

// for local

// public function syncToCloud(Request $request)
// {
//     $unsyncedSuppliers = Supplier::where('status_id', 2)->get();
//     Log::info("Starting sync for " . $unsyncedSuppliers);
//     foreach ($unsyncedSuppliers as $supplier) {
//         try {
//             $response = Http::withToken($request->token)
//             ->post(
//                 'http://192.168.1.70/backend/api/suppliers/sync',
//                 $supplier->toArray()
//             );
//             Log::info("Sync response for supplier {$supplier->id}: " . $response->body());
//             if ($response->successful()) {
//                 $supplier->update(['status_id' => 1]);
//             }
//         } catch (\Exception $e) {
//             Log::error("Sync failed for supplier$supplier {$supplier->id}: " . $e->getMessage());
//         }
//     }
//     return response()->json(['message' => 'Sync completed', 'total_synced' => $unsyncedSuppliers->count()]);
// }