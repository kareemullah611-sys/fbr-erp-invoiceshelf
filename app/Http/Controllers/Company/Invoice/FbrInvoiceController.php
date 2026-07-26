<?php

namespace App\Http\Controllers\Company\Invoice;

use App\Http\Controllers\Controller;
use App\Http\Resources\FbrInvoiceSubmissionResource;
use App\Models\Invoice;
use App\Services\Fbr\FbrDigitalInvoicingService;
use Illuminate\Http\JsonResponse;

class FbrInvoiceController extends Controller
{
    public function __construct(
        private readonly FbrDigitalInvoicingService $fbrDigitalInvoicingService,
    ) {}

    public function validateInvoice(Invoice $invoice): FbrInvoiceSubmissionResource
    {
        $this->authorize('send invoice', $invoice);

        return new FbrInvoiceSubmissionResource(
            $this->fbrDigitalInvoicingService->validate($invoice)
        );
    }

    public function readiness(Invoice $invoice): JsonResponse
    {
        $this->authorize('send invoice', $invoice);

        return response()->json([
            'data' => $this->fbrDigitalInvoicingService->readiness($invoice),
        ]);
    }

    public function submit(Invoice $invoice): FbrInvoiceSubmissionResource
    {
        $this->authorize('send invoice', $invoice);

        return new FbrInvoiceSubmissionResource(
            $this->fbrDigitalInvoicingService->submit($invoice)
        );
    }
}
