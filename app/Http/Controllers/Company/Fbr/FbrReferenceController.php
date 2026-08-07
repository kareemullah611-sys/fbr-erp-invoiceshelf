<?php

namespace App\Http\Controllers\Company\Fbr;

use App\Http\Controllers\Controller;
use App\Models\CompanySetting;
use App\Services\Fbr\FbrReferenceDataService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FbrReferenceController extends Controller
{
    public function __construct(
        private readonly FbrReferenceDataService $referenceDataService,
    ) {}

    /**
     * Search the imported HS code catalogue by code or description.
     */
    public function searchHsCodes(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search', ''));

        $dataset = $this->referenceDataService->dataset($this->environment($request));

        $codes = collect($dataset['hs_codes'])
            ->filter(function (string $description, string $code) use ($search) {
                if ($search === '') {
                    return true;
                }

                return str_contains(strtoupper($code), strtoupper($search))
                    || str_contains(strtoupper($description), strtoupper($search));
            })
            ->take(20)
            ->map(fn (string $description, string $code) => [
                'hs_code' => $code,
                'description' => $description,
                'uoms' => $dataset['hs_uoms'][$code] ?? [],
            ])
            ->values()
            ->all();

        return response()->json([
            'data' => $codes,
        ]);
    }

    /**
     * Return the list of unit of measurements.
     */
    public function uoms(Request $request): JsonResponse
    {
        $dataset = $this->referenceDataService->dataset($this->environment($request));

        $uoms = collect($dataset['uoms'])
            ->map(fn (array $uom) => $uom['description'])
            ->filter()
            ->unique()
            ->values()
            ->all();

        return response()->json([
            'data' => $uoms,
        ]);
    }

    private function environment(Request $request): string
    {
        $companyId = $request->header('company');

        if ($companyId && CompanySetting::getSetting('fbr_environment', $companyId) === 'production') {
            return 'production';
        }

        return 'sandbox';
    }
}
