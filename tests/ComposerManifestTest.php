<?php

// The two Composer manifests (design 1.0 §5.2, ADR-045):
// - composer.json is the published manifest: no repositories, no dev stability, so a standalone clone and the
//   Packagist release resolve maxcxam/larsadmin from Packagist;
// - composer.monorepo.json is the monorepo development manifest (COMPOSER=composer.monorepo.json): the same
//   manifest plus a path repository to ../larsadmin and minimum-stability dev.
// Everything except those monorepo-only keys must stay identical.

const MONOREPO_ONLY_KEYS = ['repositories', 'minimum-stability', 'prefer-stable'];

/** @return array<string, mixed> */
function composerManifest(string $file): array
{
    $path = dirname(__DIR__).DIRECTORY_SEPARATOR.$file;

    expect($path)->toBeFile();

    return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
}

/** @return array<string, mixed> */
function withoutMonorepoKeys(array $manifest): array
{
    return array_diff_key($manifest, array_flip(MONOREPO_ONLY_KEYS));
}

it('keeps composer.monorepo.json in sync with composer.json', function () {
    $published = withoutMonorepoKeys(composerManifest('composer.json'));
    $monorepo = withoutMonorepoKeys(composerManifest('composer.monorepo.json'));

    // Same keys in the same order, same values (assoc arrays compared with order, so a reordered section shows up).
    expect(array_keys($monorepo))->toBe(array_keys($published))
        ->and($monorepo)->toBe($published);
});

it('publishes a manifest that installs standalone', function () {
    $published = composerManifest('composer.json');

    expect($published)->not->toHaveKey('repositories')
        ->and($published['minimum-stability'] ?? 'stable')->toBe('stable')
        ->and($published['name'])->toBe('maxcxam/larsadmin-medialibrary')
        ->and($published['license'])->toBe('MIT')
        ->and($published['require'])->toMatchArray([
            'php' => '^8.3',
            'maxcxam/larsadmin' => '^1.6',
            'spatie/laravel-medialibrary' => '^11.23',
        ]);

    foreach ($published['require'] + $published['require-dev'] as $package => $constraint) {
        expect($constraint)->not->toContain('dev', "{$package} must not require a dev version");
    }
});

it('develops against the monorepo core through a path repository', function () {
    $monorepo = composerManifest('composer.monorepo.json');

    expect($monorepo['repositories'] ?? [])->toBe([[
        'type' => 'path',
        'url' => '../larsadmin',
        'options' => [
            'symlink' => true,
            'versions' => ['maxcxam/larsadmin' => '1.x-dev'],
        ],
    ]])
        ->and($monorepo['minimum-stability'] ?? null)->toBe('dev')
        ->and($monorepo['prefer-stable'] ?? null)->toBeTrue();
});
