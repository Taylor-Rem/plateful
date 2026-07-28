<?php

namespace App\Http\Controllers\Admin\TenantAdmin;

use App\Data\RestaurantData;
use App\Enums\MenuImportStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\MenuImportConfirmRequest;
use App\Jobs\ExtractMenuJob;
use App\Models\MenuImport;
use App\Models\Restaurant;
use App\Services\PhotoConversionService;
use App\Services\RestaurantImageService;
use App\Support\Menus\MenuBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class MenuImportController extends Controller
{
    /**
     * Accept menu photos / a PDF, convert photos to webp, and queue the
     * extraction. The wizard's menu step polls the onboarding page for
     * status from here on.
     */
    public function store(Request $request, Restaurant $restaurant, PhotoConversionService $photos): RedirectResponse
    {
        $request->validate([
            'files' => ['required', 'array', 'min:1', 'max:'.config('menu_import.max_files')],
            'files.*' => ['required', 'file', PhotoConversionService::acceptedPhotoMimes().',pdf', 'max:'.config('menu_import.max_file_kb')],
        ]);

        if ($restaurant->menuImports()->whereIn('status', [
            MenuImportStatus::Queued,
            MenuImportStatus::Processing,
            MenuImportStatus::NeedsReview,
        ])->exists()) {
            throw ValidationException::withMessages([
                'files' => 'A menu import is already in progress — finish or discard it first.',
            ]);
        }

        // A fresh attempt replaces any earlier failed one.
        $restaurant->menuImports()
            ->where('status', MenuImportStatus::Failed)
            ->get()
            ->each(fn (MenuImport $failed) => $this->deleteImport($failed));

        $disk = Storage::disk(RestaurantImageService::disk());
        $directory = "restaurants/{$restaurant->id}/menu-imports/".Str::uuid();
        $paths = [];

        foreach ($request->file('files') as $index => $file) {
            $page = $index + 1;

            if (strtolower((string) $file->getClientOriginalExtension()) === 'pdf') {
                $path = "{$directory}/source-{$page}.pdf";
                $disk->put($path, (string) $file->get());
            } else {
                $path = "{$directory}/page-{$page}.webp";

                try {
                    $disk->put($path, $photos->toWebp($file, (int) config('menu_import.photo_max_dimension')));
                } catch (Throwable) {
                    $disk->deleteDirectory($directory);

                    throw ValidationException::withMessages([
                        'files' => "We couldn’t read file #{$page}. Try re-taking the photo or exporting it as JPEG.",
                    ]);
                }
            }

            $paths[] = $path;
        }

        $import = MenuImport::create([
            'restaurant_id' => $restaurant->id,
            'status' => MenuImportStatus::Queued,
            'file_paths' => $paths,
        ]);

        ExtractMenuJob::dispatch($import);

        return back()->with('success', 'Got it — reading your menu now.');
    }

    /**
     * Full-screen review of the extracted draft before anything touches the
     * real menu.
     */
    public function review(Restaurant $restaurant, MenuImport $menuImport): Response|RedirectResponse
    {
        $this->ensureBelongs($restaurant, $menuImport);

        if ($menuImport->status !== MenuImportStatus::NeedsReview) {
            return redirect()->to($this->doneUrl($restaurant));
        }

        $disk = Storage::disk(RestaurantImageService::disk());

        return Inertia::render('Admin/TenantAdmin/MenuImportReview', [
            'restaurant' => RestaurantData::fromModel($restaurant),
            'backUrl' => $this->doneUrl($restaurant),
            // Confirming replaces any existing menu — the review screen warns
            // with the current size so the owner knows what they're trading.
            'existingItemCount' => $restaurant->menuItems()->count(),
            'menuImport' => [
                'id' => $menuImport->id,
                'categories' => $menuImport->result['categories'] ?? [],
                'optionSets' => $menuImport->result['option_sets'] ?? [],
                'warnings' => $menuImport->result['warnings'] ?? [],
                'itemCount' => $menuImport->itemCount(),
                'fileUrls' => array_map(
                    fn (string $path): string => $disk->url($path),
                    array_values(array_filter(
                        $menuImport->file_paths,
                        fn (string $path): bool => ! Str::endsWith($path, '.pdf'),
                    )),
                ),
            ],
        ]);
    }

    /**
     * Import the owner-confirmed (possibly edited) draft into the real menu.
     * Any existing menu is replaced in the same transaction: categories
     * cascade to items and cart lines, order history keeps its snapshots
     * (order_items null their menu_item_id), and item templates are kept so
     * hand-built ones survive a re-import.
     */
    public function confirm(
        MenuImportConfirmRequest $request,
        Restaurant $restaurant,
        MenuImport $menuImport,
        MenuBuilder $menuBuilder,
    ): RedirectResponse {
        $this->ensureBelongs($restaurant, $menuImport);

        if ($menuImport->status !== MenuImportStatus::NeedsReview) {
            return back()->with('error', 'This import is no longer awaiting review.');
        }

        $validated = $request->validated();
        $optionSets = $validated['option_sets'] ?? [];

        $replaced = false;
        $created = DB::transaction(function () use ($restaurant, $validated, $optionSets, $menuBuilder, &$replaced): int {
            $replaced = $restaurant->menuCategories()->exists();
            $restaurant->menuCategories()->delete();

            return $menuBuilder->buildFromImport($restaurant, $validated['categories'], $optionSets);
        });

        $menuImport->update(['status' => MenuImportStatus::Completed]);

        $summary = ($replaced ? 'Menu replaced — ' : 'Menu imported — ')."{$created} items";
        if (($sets = count($optionSets)) > 0) {
            $summary .= ' and '.$sets.' option '.($sets === 1 ? 'template' : 'templates');
        }

        return redirect()
            ->to($this->doneUrl($restaurant))
            ->with('success', "{$summary} are ready. You can fine-tune them in the menu builder anytime.");
    }

    /**
     * Throw the draft away (owner wants to retry or do it another way).
     */
    public function discard(Restaurant $restaurant, MenuImport $menuImport): RedirectResponse
    {
        $this->ensureBelongs($restaurant, $menuImport);

        if ($menuImport->status === MenuImportStatus::Queued || $menuImport->status === MenuImportStatus::Processing) {
            return back()->with('error', 'Hang on — this import is still running.');
        }

        $this->deleteImport($menuImport);

        return redirect()
            ->to($this->doneUrl($restaurant))
            ->with('success', 'Import discarded.');
    }

    /**
     * Where import flows land: the onboarding wizard while the restaurant is
     * still setting up, the menu page once it has finished onboarding.
     */
    private function doneUrl(Restaurant $restaurant): string
    {
        $routeName = $restaurant->onboarding_completed_at !== null
            ? 'admin.restaurant.menu.index'
            : 'admin.restaurant.onboarding.show';

        return route($routeName, ['restaurant' => $restaurant->subdomain]);
    }

    private function ensureBelongs(Restaurant $restaurant, MenuImport $menuImport): void
    {
        abort_unless($menuImport->restaurant_id === $restaurant->id, 404);
    }

    private function deleteImport(MenuImport $import): void
    {
        $paths = $import->file_paths;

        if ($paths !== []) {
            Storage::disk(RestaurantImageService::disk())
                ->deleteDirectory(Str::beforeLast($paths[0], '/'));
        }

        $import->delete();
    }
}
