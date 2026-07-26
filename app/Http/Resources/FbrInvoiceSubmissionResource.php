<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FbrInvoiceSubmissionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'environment' => $this->environment,
            'status' => $this->status,
            'fbr_invoice_number' => $this->fbr_invoice_number,
            'request_payload' => $this->request_payload,
            'response_payload' => $this->response_payload,
            'error_message' => $this->error_message,
            'submitted_at' => $this->submitted_at,
        ];
    }
}
