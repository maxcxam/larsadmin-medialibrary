<?php

use Inertia\Testing\AssertableInertia;
use Maxcxam\Larsadmin\Larsadmin;
use Maxcxam\LarsadminMedialibrary\MedialibraryServiceProvider;

it('registers the built extension with its translations', function () {
    $this->assertExtensionRegistered(MedialibraryServiceProvider::NAME);

    expect(Larsadmin::extensionFor(MedialibraryServiceProvider::NAME)->styleFile())->toBe('extension.css');
});

it('serves the entry module and the stylesheet to panel users', function () {
    $this->actingAs($this->admin);

    $this->extensionAssetResponse(MedialibraryServiceProvider::NAME, 'extension.js')->assertOk()
        ->assertHeader('Content-Type', 'text/javascript; charset=utf-8');
    $this->extensionAssetResponse(MedialibraryServiceProvider::NAME, 'extension.css')->assertOk();
});

it('shares the extension and its translations with authorized users only', function () {
    registerArticles();
    app()->setLocale('uk');

    $this->actingAs($this->admin)->get('/admin/entities/articles')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('larsadmin.extensions.0.name', MedialibraryServiceProvider::NAME)
        ->where('larsadmin.extensionTranslations.larsadmin-medialibrary.choose', 'Вибрати файл')
        ->etc());

    auth()->logout();
    $this->get('/admin/login')->assertInertia(fn (AssertableInertia $page) => $page->where('larsadmin.extensions', [])->etc());
});
