<?php

namespace App\Services\Fbr;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

class FbrReferenceDataService
{
    private const BASE_URL = 'https://gw.fbr.gov.pk/pdi';

    private const UOM_ANNEXURE_IDS = [1, 2, 3, 4, 5, 6];

    /**
     * Fetch the full HS code catalogue (code + description).
     *
     * @return array<int, array{hS_CODE: string, description: string}>
     */
    public function hsCodes(string $token): array
    {
        $response = $this->get($token, '/v1/itemdesccode');

        return $this->jsonArray($response);
    }

    /**
     * Fetch the list of unit of measurements.
     *
     * @return array<int, array{uoM_ID: int, description: string}>
     */
    public function uoms(string $token): array
    {
        $response = $this->get($token, '/v1/uom');

        return $this->jsonArray($response);
    }

    /**
     * Fetch the allowed UOMs for a given HS code.
     *
     * @return array<int, array{uoM_ID: int, description: string}>
     */
    public function hsUoms(string $token, string $hsCode): array
    {
        foreach (self::UOM_ANNEXURE_IDS as $annexureId) {
            $response = $this->get($token, '/v2/HS_UOM', [
                'hs_code' => $hsCode,
                'annexure_id' => $annexureId,
            ]);

            $payload = $this->jsonArray($response);

            if ($payload !== []) {
                return $payload;
            }
        }

        return [];
    }

    /**
     * Build a consolidated reference dataset.
     *
     * @return array{
     *   generated_at: string,
     *   hs_codes: array<string, string>,
     *   hs_uoms: array<string, list<string>>,
     *   uoms: array<int, array{uoM_ID: int, description: string}>,
     * }
     */
    public function referenceDataset(string $token, ?callable $onProgress = null): array
    {
        $hsCodes = $this->hsCodes($token);
        $uoms = $this->uoms($token);

        $hsUoms = [];
        $total = count($hsCodes);

        foreach ($hsCodes as $index => $item) {
            $code = Arr::get($item, 'hS_CODE');

            if (blank($code)) {
                continue;
            }

            $allowed = collect($this->hsUoms($token, $code))
                ->map(fn ($entry) => (string) Arr::get($entry, 'description'))
                ->filter()
                ->values()
                ->all();

            if ($allowed !== []) {
                $hsUoms[$code] = $allowed;
            }

            if ($onProgress) {
                $onProgress($index + 1, $total, $code);
            }
        }

        return [
            'generated_at' => now()->toIso8601String(),
            'hs_codes' => collect($hsCodes)
                ->mapWithKeys(fn ($item) => [(string) Arr::get($item, 'hS_CODE') => (string) Arr::get($item, 'description')])
                ->filter(fn ($description, $code) => $code !== '')
                ->all(),
            'hs_uoms' => $hsUoms,
            'uoms' => $uoms,
        ];
    }

    /**
     * Resolve the bearer token from an explicit value, company settings, or config.
     */
    public function resolveToken(?string $token = null, string $environment = 'sandbox'): ?string
    {
        if (filled($token)) {
            return $token;
        }

        return config("fbr.{$environment}_token");
    }

    /**
     * Load a previously imported reference dataset from storage.
     *
     * @return array{
     *   generated_at: ?string,
     *   hs_codes: array<string, string>,
     *   hs_uoms: array<string, list<string>>,
     *   uoms: array<int, array{uoM_ID: int, description: string}>,
     * }
     */
    public function dataset(string $environment = 'sandbox'): array
    {
        $path = $this->datasetPath($environment);

        if (! File::exists($path)) {
            return [
                'generated_at' => null,
                'hs_codes' => [],
                'hs_uoms' => [],
                'uoms' => [],
            ];
        }

        $decoded = json_decode((string) File::get($path), true);

        return is_array($decoded) ? $decoded : [
            'generated_at' => null,
            'hs_codes' => [],
            'hs_uoms' => [],
            'uoms' => [],
        ];
    }

    public function datasetPath(string $environment = 'sandbox'): string
    {
        return storage_path("app/fbr/reference-{$environment}.json");
    }

    private function get(string $token, string $path, array $query = []): Response
    {
        try {
            return Http::withToken($token)
                ->timeout(config('fbr.timeout', 300))
                ->get(self::BASE_URL.$path, $query);
        } catch (ConnectionException $exception) {
            throw new ConnectionException(
                "FBR reference API request to {$path} failed to connect: {$exception->getMessage()}",
                $exception->getCode(),
                $exception
            );
        }
    }

    /**
     * @return array<int|string, mixed>
     */
    private function jsonArray(Response $response): array
    {
        if ($response->failed()) {
            throw new \RuntimeException(
                "FBR reference API returned HTTP {$response->status()}: ".trim($response->body())
            );
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            return [];
        }

        return $payload;
    }
}
