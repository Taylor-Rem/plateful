<?php

namespace App\Jobs;

use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\RateLimitException;
use App\Enums\MenuImportStatus;
use App\Models\MenuImport;
use App\Services\MenuExtractionService;
use App\Services\RestaurantImageService;
use App\Support\Menus\ExtractedMenuSanitizer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class ExtractMenuJob implements ShouldQueue
{
    use Queueable;

    /**
     * Extraction is a single long API call; don't let the worker kill it
     * early, and don't retry automatically — each attempt costs real money,
     * so a failure surfaces to the owner with a "try again" instead.
     */
    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(public MenuImport $import) {}

    public function handle(MenuExtractionService $extraction): void
    {
        $this->import->refresh();

        if ($this->import->status !== MenuImportStatus::Queued) {
            return;
        }

        $this->import->update(['status' => MenuImportStatus::Processing]);

        try {
            $result = $extraction->extract($this->loadFiles());
            $sanitized = ExtractedMenuSanitizer::sanitize($result['categories'], $result['warnings']);

            $this->import->update([
                'status' => MenuImportStatus::NeedsReview,
                'result' => $sanitized,
                'model' => $result['model'],
                'input_tokens' => $result['input_tokens'],
                'output_tokens' => $result['output_tokens'],
                'error' => null,
            ]);
        } catch (Throwable $e) {
            report($e);

            $this->import->update([
                'status' => MenuImportStatus::Failed,
                'error' => $this->friendlyError($e),
            ]);
        }
    }

    /**
     * The queue exhausted retries or the job timed out — make sure the owner
     * isn't left staring at an eternal spinner.
     */
    public function failed(?Throwable $e): void
    {
        $this->import->refresh();

        if ($this->import->status === MenuImportStatus::Processing || $this->import->status === MenuImportStatus::Queued) {
            $this->import->update([
                'status' => MenuImportStatus::Failed,
                'error' => 'Menu extraction took too long. Please try again.',
            ]);
        }
    }

    /**
     * @return array<int, array{media_type: string, data: string}>
     */
    private function loadFiles(): array
    {
        $disk = Storage::disk(RestaurantImageService::disk());

        return array_map(fn (string $path): array => [
            'media_type' => Str::endsWith($path, '.pdf') ? 'application/pdf' : 'image/webp',
            'data' => (string) $disk->get($path),
        ], $this->import->file_paths);
    }

    private function friendlyError(Throwable $e): string
    {
        return match (true) {
            $e instanceof AuthenticationException => 'The menu-import service is misconfigured (invalid API key). Please contact support.',
            $e instanceof RateLimitException => 'The menu-import service is busy right now. Please try again in a minute.',
            $e instanceof APIConnectionException => 'We couldn’t reach the menu-import service. Please try again.',
            str_contains($e->getMessage(), 'No menu items') => 'We couldn’t read any menu items from those files. Try clearer photos, or build your menu another way.',
            default => 'Something went wrong while reading your menu. Please try again.',
        };
    }
}
