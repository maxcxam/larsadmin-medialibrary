<?php

namespace Maxcxam\LarsadminMedialibrary\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Inertia\ServiceProvider as InertiaServiceProvider;
use Maxcxam\Larsadmin\Larsadmin;
use Maxcxam\Larsadmin\LarsadminServiceProvider;
use Maxcxam\LarsadminMedialibrary\MedialibraryServiceProvider;
use Maxcxam\LarsadminMedialibrary\Tests\Fixtures\Article;
use Maxcxam\LarsadminMedialibrary\Tests\Fixtures\ArticlePolicy;
use Maxcxam\LarsadminMedialibrary\Tests\Fixtures\Entities\Articles;
use Maxcxam\LarsadminMedialibrary\Tests\Fixtures\User;
use Orchestra\Testbench\TestCase as Orchestra;
use Spatie\MediaLibrary\MediaLibraryServiceProvider as SpatieMediaLibraryServiceProvider;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        // Static panel state is flushed BEFORE the application boots: the extension's provider registers itself
        // (Larsadmin::extension(), Larsadmin::routes()) while booting. Flushing after boot, as larsadmin's own
        // TestCase does, would drop that registration.
        Larsadmin::flushState();
        ArticlePolicy::reset();
        Article::$queueThumbs = false;
        Articles::$contractVisible = true;

        parent::setUp();

        Storage::fake('media_public');
        Storage::fake('media_private');

        Larsadmin::auth(fn () => true);
        Gate::policy(Article::class, ArticlePolicy::class);

        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret']);
    }

    protected function getPackageProviders($app): array
    {
        return [
            InertiaServiceProvider::class,
            LarsadminServiceProvider::class,
            SpatieMediaLibraryServiceProvider::class,
            MedialibraryServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $config = $app['config'];
        $config->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $config->set('auth.providers.users.model', User::class);
        $config->set('inertia.testing.ensure_pages_exist', false);
        $config->set('filesystems.disks.media_public', ['driver' => 'local', 'root' => sys_get_temp_dir().'/lml-public', 'url' => '/storage', 'visibility' => 'public']);
        $config->set('filesystems.disks.media_private', ['driver' => 'local', 'root' => sys_get_temp_dir().'/lml-private']);
        $config->set('media-library.disk_name', 'media_private');
        $config->set('media-library.queue_conversions_by_default', false);
        $config->set('media-library.queue_connection_name', 'sync');
    }

    protected function defineDatabaseMigrations(): void
    {
        (require __DIR__.'/../vendor/spatie/laravel-medialibrary/database/migrations/create_media_table.php.stub')->up();
        $this->loadMigrationsFrom(__DIR__.'/../vendor/maxcxam/larsadmin/database/migrations');

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('articles', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->timestamps();
        });
    }
}
