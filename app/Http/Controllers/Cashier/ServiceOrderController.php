<?php

namespace App\Http\Controllers\Cashier;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Invoice;
use App\Models\Client;
use App\Models\Vehicle;
use App\Models\Inventory;
use App\Models\Technician;

class ServiceOrderController extends Controller
{
    public function index()
    {
        $clients = Client::all();
        $vehicles = Vehicle::all();
        $parts = Inventory::all();
        $technicians = Technician::all();

        $history = Invoice::with(['client', 'vehicle'])
            ->where('source_type', 'service_order')
            ->orderBy('created_at', 'desc')
            ->get();

        return view('cashier.service-order', compact('clients', 'vehicles', 'parts', 'technicians', 'history'));
    }

    public function create()
    {
        $clients = Client::all();
        $vehicles = Vehicle::all();
        $parts = Inventory::select('id', 'item_name', 'quantity', 'selling')->get(); // Select quantity as the remaining stock
        $technicians = Technician::all();
        $history = collect([]);

        return view('cashier.service-order', compact('clients', 'vehicles', 'parts', 'technicians', 'history'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'client_id'     => 'nullable|exists:clients,id',
            'vehicle_id'    => 'nullable|exists:vehicles,id',
            'customer_name' => 'nullable|string',
            'vehicle_name'  => 'nullable|string',
            'plate'         => 'nullable|string',
            'model'         => 'nullable|string',
            'year'          => 'nullable|string',
            'color'         => 'nullable|string',
            'odometer'      => 'nullable|string',
            'subtotal'      => 'required|numeric',
            'total_discount'=> 'required|numeric',
            'vat_amount'    => 'required|numeric',
            'grand_total'   => 'required|numeric',
            'payment_type'  => 'required|string'
        ]);

        // Vehicle logic
        $vehicleId = $request->vehicle_id;
        if ($vehicleId) {
            $vehicle = Vehicle::find($vehicleId);
            if ($vehicle) {
                $vehicle->update([
                    'plate_number' => $request->plate,
                    'model'        => $request->model,
                    'year'         => $request->year,
                    'color'        => $request->color,
                    'odometer'     => $request->odometer,
                ]);
            }
        } else if ($request->plate || $request->model || $request->year || $request->color || $request->odometer) {
            $vehicle = Vehicle::create([
                'plate_number' => $request->plate,
                'model'        => $request->model,
                'year'         => $request->year,
                'color'        => $request->color,
                'odometer'     => $request->odometer,
                'client_id'    => $request->client_id,
            ]);
            $vehicleId = $vehicle->id;
        } else {
            $vehicleId = null;
        }

        $invoice = Invoice::create([
            'client_id'     => $request->client_id,
            'vehicle_id'    => $vehicleId,
            'customer_name' => $request->customer_name,
            'vehicle_name'  => $request->vehicle_name,
            'source_type'   => 'service_order',
            'service_status'=> 'pending',
            'status'        => 'unpaid',
            'subtotal'      => $request->subtotal,
            'total_discount'=> $request->total_discount,
            'vat_amount'    => $request->vat_amount,
            'grand_total'   => $request->grand_total,
            'payment_type'  => $request->payment_type,
            'number'        => $request->number,
            'address'       => $request->address,
        ]);

       // Save items (inventory OR manual)
if ($request->has('items')) {
    foreach ($request->items as $item) {
        $invoice->items()->create([
            'part_id'                  => $item['part_id'] ?? null,
            'manual_part_name'         => $item['manual_part_name'] ?? null,
            'manual_serial_number'     => $item['manual_serial_number'] ?? null,
            'manual_acquisition_price' => $item['manual_acquisition_price'] ?? null,
            'manual_selling_price'     => $item['manual_selling_price'] ?? null,
            'quantity'                 => $item['quantity'],
            'original_price'           => $item['original_price']  ?? ($item['manual_selling_price'] ?? 0),
            'discounted_price'         => $item['discounted_price']?? ($item['manual_selling_price'] ?? 0),
            'discount_value'           => (
                                            ($item['original_price'] ?? ($item['manual_selling_price'] ?? 0))
                                            - ($item['discounted_price'] ?? ($item['manual_selling_price'] ?? 0))
                                          ),
            'line_total'               => $item['quantity']
                                          * ($item['discounted_price'] ?? ($item['manual_selling_price'] ?? 0)),
        ]);
    }
}

        // Save jobs
        if ($request->has('jobs')) {
            foreach ($request->jobs as $job) {
                $invoice->jobs()->create([
                    'job_description' => $job['job_description'] ?? '',
                    'technician_id'   => $job['technician_id'] ?? null,
                    'total'           => $job['total'] ?? 0,
                ]);
            }
        }

        return redirect()->route('cashier.serviceorder.index')->with('success', 'Service Order created!');
    }

    public function edit($id)
    {
        $invoice = Invoice::with(['items', 'jobs'])->findOrFail($id);
        $clients = Client::all();
        $vehicles = Vehicle::all();
        $parts = Inventory::all();
        $technicians = Technician::all();

        $history = Invoice::with(['client', 'vehicle'])
            ->where('source_type', 'service_order')
            ->orderBy('created_at', 'desc')
            ->get();

        return view('cashier.service-order', compact('invoice', 'clients', 'vehicles', 'parts', 'technicians', 'history'));
    }

    public function update(Request $request, $id)
    {
        $invoice = Invoice::findOrFail($id);

        // Fast update for just the source_type
        if ($request->has('quick_update') && $request->has('source_type')) {
            $invoice->update([
                'source_type' => $request->source_type
            ]);
            return redirect()->route('cashier.serviceorder.index')->with('success', 'Status updated!');
        }

        $request->validate([
            'client_id'     => 'nullable|exists:clients,id',
            'vehicle_id'    => 'nullable|exists:vehicles,id',
            'customer_name' => 'nullable|string',
            'vehicle_name'  => 'nullable|string',
            'plate'         => 'nullable|string',
            'model'         => 'nullable|string',
            'year'          => 'nullable|string',
            'color'         => 'nullable|string',
            'odometer'      => 'nullable|string',
            'subtotal'      => 'required|numeric',
            'total_discount'=> 'required|numeric',
            'vat_amount'    => 'required|numeric',
            'grand_total'   => 'required|numeric',
            'payment_type'  => 'required|string',
            'number'        => 'nullable|string',
            'address'       => 'nullable|string',
            
        ]);

        $vehicleId = $request->vehicle_id;
        if ($vehicleId) {
            $vehicle = Vehicle::find($vehicleId);
            if ($vehicle) {
                $vehicle->update([
                    'plate_number' => $request->plate,
                    'model'        => $request->model,
                    'year'         => $request->year,
                    'color'        => $request->color,
                    'odometer'     => $request->odometer,
                ]);
            }
        } else if ($request->plate || $request->model || $request->year || $request->color || $request->odometer) {
            $vehicle = Vehicle::create([
                'plate_number' => $request->plate,
                'model'        => $request->model,
                'year'         => $request->year,
                'color'        => $request->color,
                'odometer'     => $request->odometer,
                'client_id'    => $request->client_id,
            ]);
            $vehicleId = $vehicle->id;
        } else {
            $vehicleId = null;
        }

        $invoice->update([
            'client_id'     => $request->client_id,
            'vehicle_id'    => $vehicleId,
            'customer_name' => $request->customer_name,
            'vehicle_name'  => $request->vehicle_name,
            'source_type'   => 'service_order',
            'service_status'=> 'pending',
            'status'        => 'unpaid',
            'subtotal'      => $request->subtotal,
            'total_discount'=> $request->total_discount,
            'vat_amount'    => $request->vat_amount,
            'grand_total'   => $request->grand_total,
            'payment_type'  => $request->payment_type,
        ]);


// Update items (delete old, add new: inventory OR manual)
$invoice->items()->delete();
if ($request->has('items')) {
    foreach ($request->items as $item) {
        $invoice->items()->create([
            'part_id'                  => $item['part_id'] ?? null,
            'manual_part_name'         => $item['manual_part_name'] ?? null,
            'manual_serial_number'     => $item['manual_serial_number'] ?? null,
            'manual_acquisition_price' => $item['manual_acquisition_price'] ?? null,
            'manual_selling_price'     => $item['manual_selling_price'] ?? null,
            'quantity'                 => $item['quantity'],
            'original_price'           => $item['original_price']  ?? ($item['manual_selling_price'] ?? 0),
            'discounted_price'         => $item['discounted_price']?? ($item['manual_selling_price'] ?? 0),
            'discount_value'           => (
                                            ($item['original_price'] ?? ($item['manual_selling_price'] ?? 0))
                                            - ($item['discounted_price'] ?? ($item['manual_selling_price'] ?? 0))
                                          ),
            'line_total'               => $item['quantity']
                                          * ($item['discounted_price'] ?? ($item['manual_selling_price'] ?? 0)),
        ]);
    }
}


        // Update jobs (delete old, add new)
        $invoice->jobs()->delete();
        if ($request->has('jobs')) {
            foreach ($request->jobs as $job) {
                $invoice->jobs()->create([
                    'job_description' => $job['job_description'] ?? '',
                    'technician_id'   => $job['technician_id'] ?? null,
                    'total'           => $job['total'] ?? 0,
                ]);
            }
        }

        return redirect()->route('cashier.serviceorder.index')->with('success', 'Service Order updated!');
    }

    public function destroy($id)
    {
        $invoice = Invoice::findOrFail($id);
        $invoice->items()->delete();
        $invoice->jobs()->delete();
        $invoice->delete();

        return redirect()->route('cashier.serviceorder.index')->with('success', 'Service Order deleted!');
    }

    public function view($id)
    {
        $invoice = Invoice::with([
            'client',
            'vehicle',
            'items.part',
            'jobs.technician'
        ])->findOrFail($id);

        return view('cashier.service-order-view', compact('invoice'));
    }
    public function show($id)
    {
        $invoice = Invoice::with([
            'client',
            'vehicle',
            'items.part',
            'jobs.technician'
        ])->findOrFail($id);

        // This assumes you want to reuse the view for showing details
        return view('cashier.service-order-view', compact('invoice'));
    }

}
