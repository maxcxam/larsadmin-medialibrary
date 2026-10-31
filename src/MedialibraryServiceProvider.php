<?php

namespace Maxcxam\LarsadminMedialibrary;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Maxcxam\Larsadmin\Larsadmin;
use Maxcxam\LarsadminMedialibrary\Http\MediaController;

/**
 * Registers the extension (built files in dist/, translations) and its routes. Only the public extension API of
 * larsadmin is used (design 1.5 §4.1): Larsadmin::extension(), Larsadmin::routes().
 */
class MedialibraryServiceProvider extends ServiceProvider
{
    public const string NAME = 'maxcxam/larsadmin-medialibrary';

    public const string LANG = 'larsadmin-medialibrary';

    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../lang', self::LANG);

        if (is_dir(__DIR__.'/../dist')) {
            Larsadmin::extension(self::NAME, __DIR__.'/../dist')->translations(self::LANG);
        }

        // Routes live under {path}/ext behind the panel gate; records are authorized in the controller with
        // Larsadmin::findRecord(). Names: larsadmin.ext.medialibrary.*
        Larsadmin::routes(function () {
            Route::prefix('medialibrary/{entity}/{id}/{field}')
                ->name('medialibrary.')
                ->where(['field' => '[A-Za-z0-9_.-]+', 'media' => '[0-9]+'])
                ->group(function () {
                    Route::get('{media}', [MediaController::class, 'show'])
                        ->middleware(['signed:relative', 'throttle:larsadmin-files'])
                        ->name('show');
                    Route::post('order', [MediaController::class, 'reorder'])->name('reorder');
                    Route::delete('{media}', [MediaController::class, 'destroy'])->name('destroy');
                });
        });
    }
}
