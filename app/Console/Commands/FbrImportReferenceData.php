<?php

namespace App\Console\Commands;

use App\Services\Fbr\FbrReferenceDataService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\File;
use RuntimeException;

#[Signature('fbr:import-reference-data {--token= : FBR bearer token (defaults to config)} {--environment=sandbox : sandbox|production} {--output= : Path to write the JSON file}')]
#[Description('Fetch all HS codes and their UOMs from FBR reference APIs and store them as JSON')]
class FbrImportReferenceData extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(FbrReferenceDataService $service): int
    {
        $token = $service->resolveToken($this->option('token'), $this->option('environment'));

        if (blank($token)) {
            $this->error("No FBR {$this->option('environment')} bearer token configured. Pass one with --token.");

            return self::FAILURE;
        }

        $output = $this->option('output')
            ?: storage_path("app/fbr/reference-{$this->option('environment')}.json");

        $this->info('Fetching FBR reference data (this may take a while: one HS_UOM call per HS code)...');

        try {
            $dataset = $service->referenceDataset($token, function (int $processed, int $total, string $code) {
                if ($this->output->isVeryVerbose()) {
                    $this->line("  [{$processed}/{$total}] {$code}");
                } else {
                    $this->output->write("\r  {$processed}/{$total} HS codes");
                }
            });
        } catch (ConnectionException|RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $directory = dirname($output);

        if (! File::isDirectory($directory)) {
            File::makeDirectory($directory, 0755, true);
        }

        File::put($output, json_encode($dataset, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->newLine();
        $this->info('Done.');

        $this->table(
            ['Metric', 'Count'],
            [
                ['HS codes', number_format(count($dataset['hs_codes']))],
                ['HS codes with UOM mapping', number_format(count($dataset['hs_uoms']))],
                ['UOMs', number_format(count($dataset['uoms']))],
            ]
        );

        $this->info('Wrote '.File::size($output)." bytes to {$output}");

        return self::SUCCESS;
    }
}
