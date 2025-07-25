<?php

namespace App\Http\Controllers\Cashier;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Invoice;
use App\Models\Client;
use App\Models\Vehicle;
use App\Models\Inventory;
use App\Models\Technician;

class InvoiceController extends Controller
{
    public function index()
    {
        $clients = Client::all();
        $vehicles = Vehicle::all();
        $parts = Inventory::all();
        $technicians = Technician::all();

        $history = Invoice::with(['client', 'vehicle'])
            ->where('source_type', 'invoicing')
            ->orderBy('created_at', 'desc')
            ->get();

        return view('cashier.invoice', compact('clients', 'vehicles', 'parts', 'technicians', 'history'));
    }

    public function create()
    {
        $clients = Client::all();
        $vehicles = Vehicle::all();
        $parts = Inventory::select('id', 'item_name', 'quantity', 'selling')->get(); // Select quantity as the remaining stock
        $technicians = Technician::all();
        $history = collect([]);

        return view('cashier.invoice', compact('clients', 'vehicles', 'parts', 'technicians', 'history'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'client_id'      => 'nullable|exists:clients,id',
            'vehicle_id'     => 'nullable|exists:vehicles,id',
            'customer_name'  => 'nullable|string',
            'vehicle_name'   => 'nullable|string',
            'plate'          => 'nullable|string',
            'model'          => 'nullable|string',
            'year'           => 'nullable|string',
            'color'          => 'nullable|string',
            'odometer'       => 'nullable|string',
            'subtotal'       => 'required|numeric',
            'total_discount' => 'required|numeric',
            'vat_amount'     => 'required|numeric',
            'grand_total'    => 'required|numeric',
            'payment_type'   => 'required|string',
            'status'         => 'required|in:unpaid,paid,cancelled,voided',
            'service_status' => 'required|in:pending,in_progress,done',
            'invoice_no' => 'required|string|unique:invoices,invoice_no',
            'number'         => 'nullable|string',
            'address'        => 'nullable|string',
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
            'client_id'      => $request->client_id,
            'vehicle_id'     => $vehicleId,
            'customer_name'  => $request->customer_name,
            'vehicle_name'   => $request->vehicle_name,
            'source_type'    => 'invoicing',
            'service_status' => $request->service_status ?? 'pending',
            'status'         => $request->status ?? 'unpaid',
            'subtotal'       => $request->subtotal,
            'total_discount' => $request->total_discount,
            'vat_amount'     => $request->vat_amount,
            'grand_total'    => $request->grand_total,
            'payment_type'   => $request->payment_type,
            'invoice_no' => $request->invoice_no,
            'number'         => $request->number,
            'address'        => $request->address,
        ]);

        // Save items
       // Update items (delete old, add new)
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

        // Save jobs
        if ($request->has('jobs')) {
            foreach ($request->jobs as $job) {
                $techId = !empty($job['technician_id']) ? $job['technician_id'] : null;
                $invoice->jobs()->create([
                    'job_description' => $job['job_description'] ?? '',
                    'technician_id'   => $techId,
                    'total'           => $job['total'] ?? 0,
                ]);
            }
        }

        // Inventory deduction if paid
        if ($invoice->status === 'paid') {
            foreach ($invoice->items as $item) {
                $inventory = Inventory::find($item->part_id);
                if ($inventory) {
                    $inventory->deductQuantity($item->quantity);
                }
            }
        }

        return redirect()->route('cashier.invoice.index')->with('success', 'Invoice created!');
    }

    public function edit($id)
    {
        $invoice = Invoice::with(['items', 'jobs'])->findOrFail($id);
        $clients = Client::all();
        $vehicles = Vehicle::all();
        $parts = Inventory::all();
        $technicians = Technician::all();

        $history = Invoice::with(['client', 'vehicle'])
            ->where('source_type', 'invoicing')
            ->orderBy('created_at', 'desc')
            ->get();

        return view('cashier.invoice', compact('invoice', 'clients', 'vehicles', 'parts', 'technicians', 'history'));
    }

    public function update(Request $request, $id)
    {
        $invoice = Invoice::with('items')->findOrFail($id);
        $prevStatus = $invoice->status;

        // If this is a quick "mark as paid" or service_status update (not full edit)
        if ($request->has('status') && $request->method() == 'PUT' && !$request->has('items')) {
            $invoice->update([
                'status'         => $request->status,
                'service_status' => $request->service_status ?? $invoice->service_status,
            ]);

            // Deduct inventory ONLY if changing from not paid → paid
            if ($prevStatus !== 'paid' && $request->status === 'paid') {
                foreach ($invoice->items as $item) {
                    $inventory = Inventory::find($item->part_id);
                    if ($inventory) {
                        $inventory->deductQuantity($item->quantity);
                    }
                }
            }

            return redirect()->route('cashier.invoice.index')->with('success', 'Status updated!');
        }

        $request->validate([
            'client_id'      => 'nullable|exists:clients,id',
            'vehicle_id'     => 'nullable|exists:vehicles,id',
            'customer_name'  => 'nullable|string',
            'vehicle_name'   => 'nullable|string',
            'plate'          => 'nullable|string',
            'model'          => 'nullable|string',
            'year'           => 'nullable|string',
            'color'          => 'nullable|string',
            'odometer'       => 'nullable|string',
            'subtotal'       => 'required|numeric',
            'total_discount' => 'required|numeric',
            'vat_amount'     => 'required|numeric',
            'grand_total'    => 'required|numeric',
            'payment_type'   => 'required|string',
            'status'         => 'required|in:unpaid,paid,cancelled,voided',
            'service_status' => 'required|in:pending,in_progress,done',
            'invoice_no' => 'required|string|unique:invoices,invoice_no,'.$invoice->id,
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
            'client_id'      => $request->client_id,
            'vehicle_id'     => $vehicleId,
            'customer_name'  => $request->customer_name,
            'vehicle_name'   => $request->vehicle_name,
            'source_type'    => 'invoicing',
            'service_status' => $request->service_status ?? 'pending',
            'status'         => $request->status ?? 'unpaid',
            'subtotal'       => $request->subtotal,
            'total_discount' => $request->total_discount,
            'vat_amount'     => $request->vat_amount,
            'grand_total'    => $request->grand_total,
            'payment_type'   => $request->payment_type,
            'invoice_no' => $request->invoice_no,
        ]);

     // Update items (delete old, add new)
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
                $techId = !empty($job['technician_id']) ? $job['technician_id'] : null;
                $invoice->jobs()->create([
                    'job_description' => $job['job_description'] ?? '',
                    'technician_id'   => $techId,
                    'total'           => $job['total'] ?? 0,
                ]);
            }
        }

        // Deduct inventory only if marking as paid, and was not already paid
        if ($prevStatus !== 'paid' && $request->status === 'paid') {
            foreach ($invoice->items as $item) {
                $inventory = Inventory::find($item->part_id);
                if ($inventory) {
                    $inventory->deductQuantity($item->quantity);
                }
            }
        }

        return redirect()->route('cashier.invoice.index')->with('success', 'Invoice updated!');
    }

    public function destroy($id)
    {
        $invoice = Invoice::findOrFail($id);
        $invoice->items()->delete();
        $invoice->jobs()->delete();
        $invoice->delete();

        return redirect()->route('cashier.invoice.index')->with('success', 'Invoice deleted!');
    }

    public function view($id)
    {
        $invoice = Invoice::with([
            'client',
            'vehicle',
            'items.part',
            'jobs.technician'
        ])->findOrFail($id);

        return view('cashier.invoice-view', compact('invoice'));
    }

    public function show($id)
    {
        $invoice = Invoice::with([
            'client',
            'vehicle',
            'items.part',
            'jobs.technician'
        ])->findOrFail($id);

        return view('cashier.invoice-view', compact('invoice'));
    }
}
