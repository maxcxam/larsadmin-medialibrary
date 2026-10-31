<?php

use Maxcxam\LarsadminMedialibrary\Fields\Media;
use Maxcxam\LarsadminMedialibrary\Fields\MediaGallery;

/*
 * ADR-046, design §6: the skill must mention the add-on's whole public surface (every non-@internal class in
 * src/, every stable fluent method of ADR-042, every guide page), and everything the skill and the guideline
 * reference must exist and be public. Adapted from the core's tests/Ai/SkillCoverageTest.php (not shipped in
 * vendor/). Exemptions only in tests/Ai/coverage-exemptions.php.
 */

function mlCoveragePath(string $path = ''): string
{
    return str_replace('\\', '/', dirname(__DIR__, 2)).($path === '' ? '' : '/'.$path);
}

/** The installed core (vendor/maxcxam/larsadmin, a symlink to ../larsadmin in the monorepo). */
function mlCoverageCorePath(string $path = ''): string
{
    return mlCoveragePath('vendor/maxcxam/larsadmin'.($path === '' ? '' : '/'.$path));
}

/**
 * The stable API frozen for 1.x of the add-on (ADR-042): public class => its own stable methods. Inherited Field
 * methods are the core's API. Changing this list is an API change: update the skill in the same PR.
 *
 * @return array<class-string, list<string>>
 */
function mlCoverageStableApi(): array
{
    return [
        Media::class => ['make', 'collection', 'conversion', 'disk', 'types', 'images', 'maxSize', 'public', 'private', 'removable', 'stripMetadata'],
        MediaGallery::class => ['limit'],
    ];
}

/** Text of SKILL.md and references/*.md of the skill, with JSON-escaped backslashes folded. */
function mlCoverageSkillText(): string
{
    $files = [];

    foreach (glob(mlCoveragePath('resources/boost/skills/*/SKILL.md')) as $skill) {
        array_push($files, $skill, ...glob(dirname($skill).'/references/*.md'));
    }

    return str_replace('\\\\', '\\', implode("\n", array_map('file_get_contents', $files)));
}

/** @return array<string, string> package-relative path => text of the guideline and every skill file */
function mlCoverageAllAiFiles(): array
{
    $texts = [];

    foreach ([
        ...glob(mlCoveragePath('resources/boost/guidelines/*.md')),
        ...glob(mlCoveragePath('resources/boost/skills/*/SKILL.md')),
        ...glob(mlCoveragePath('resources/boost/skills/*/references/*.md')),
    ] as $file) {
        $texts[substr(str_replace('\\', '/', $file), strlen(mlCoveragePath()) + 1)] = str_replace('\\\\', '\\', file_get_contents($file));
    }

    return $texts;
}

/** @return array<string, string> exemption => reason */
function mlCoverageExemptions(): array
{
    return require __DIR__.'/coverage-exemptions.php';
}

/** @return array<string, string> FQCN => package-relative file, for every class, trait, interface and enum in src/ */
function mlCoverageSourceClasses(): array
{
    $classes = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(mlCoveragePath('src'), FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if ($file->getExtension() === 'php') {
            $relative = substr(str_replace('\\', '/', $file->getPathname()), strlen(mlCoveragePath('src')) + 1);
            $classes['Maxcxam\\LarsadminMedialibrary\\'.str_replace('/', '\\', substr($relative, 0, -4))] = 'src/'.$relative;
        }
    }

    ksort($classes);

    return $classes;
}

/**
 * Whether the class / trait / interface / enum declared in a file has @internal in its docblock, or null when the
 * file declares none. Read from the tokens (as the core does), not by reflection.
 */
function mlCoverageDeclaresInternal(string $file): ?bool
{
    $doc = null;
    $previous = null;
    $attribute = 0;

    foreach (token_get_all(file_get_contents($file)) as $token) {
        $id = is_array($token) ? $token[0] : null;

        if ($attribute > 0 || $id === T_ATTRIBUTE) {
            $attribute += match (true) {
                $id === T_ATTRIBUTE, $token === '[' => 1,
                $token === ']' => -1,
                default => 0,
            };

            continue;
        }

        if ($id === T_DOC_COMMENT) {
            $doc = $token[1];
        } elseif (in_array($id, [T_CLASS, T_TRAIT, T_INTERFACE, T_ENUM], true) && $previous !== T_DOUBLE_COLON) {
            return preg_match('/@internal\b/', (string) $doc) === 1;
        } elseif (! in_array($id, [T_WHITESPACE, T_COMMENT, T_FINAL, T_ABSTRACT, T_READONLY], true)) {
            $doc = null;
        }

        if (! in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            $previous = $id;
        }
    }

    return null;
}

/** @return list<class-string> the non-@internal classes of src/ */
function mlCoveragePublicClasses(): array
{
    return array_keys(array_filter(mlCoverageSourceClasses(), fn (string $file) => mlCoverageDeclaresInternal(mlCoveragePath($file)) === false));
}

/**
 * Public methods a class declares itself without @internal (constructor excluded), plus make() for Media.
 *
 * @return list<string>
 */
function mlCoverageDeclaredStableMethods(string $class): array
{
    $methods = $class === Media::class ? ['make'] : [];

    foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if ($method->class === $class && ! $method->isConstructor() && ! preg_match('/@internal\b/', (string) $method->getDocComment())) {
            $methods[] = $method->getName();
        }
    }

    return $methods;
}

/** Why a field method may not be used from host code, or null when it is a public, non-internal method of the class. */
function mlCoverageMethodProblem(string $class, string $method): ?string
{
    if (! method_exists($class, $method)) {
        return 'does not exist';
    }

    $reflection = new ReflectionMethod($class, $method);

    return match (true) {
        ! $reflection->isPublic() => 'is not public',
        preg_match('/@internal\b/', (string) $reflection->getDocComment()) === 1 => 'is @internal, not public API',
        in_array($method, mlCoverageCoreInternalMembers(), true) => 'is internal (INTERNAL_PHP_MEMBERS of the core)',
        default => null,
    };
}

/** @return list<string> public members the core keeps internal (INTERNAL_PHP_MEMBERS in kit/check-public-api.mjs) */
function mlCoverageCoreInternalMembers(): array
{
    $file = mlCoverageCorePath('kit/check-public-api.mjs');

    if (! is_file($file)) {
        return [];
    }

    preg_match('/const INTERNAL_PHP_MEMBERS = \[(.*?)\]/s', file_get_contents($file), $list);
    preg_match_all("/'([A-Za-z_]\\w*)'/", preg_replace('~//[^\n]*~', '', $list[1] ?? ''), $names);

    return $names[1];
}

/**
 * Fluent chains started with Media::make() or MediaGallery::make() in a PHP block: [class, method] per call.
 * Media from another namespace (the media library's model, imported without an alias) is skipped.
 *
 * @return list<array{0: class-string, 1: string}>
 */
function mlCoverageChains(string $code): array
{
    $ours = ['MediaGallery' => MediaGallery::class];

    if (! preg_match('/^use\s+(?!Maxcxam\\\\LarsadminMedialibrary\\\\)[\w\\\\]*\\\\Media\s*;/m', $code)) {
        $ours['Media'] = Media::class;
    }

    $tokens = array_values(array_filter(
        token_get_all(str_starts_with(ltrim($code), '<?php') ? $code : "<?php\n".$code),
        fn ($token) => ! is_array($token) || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG], true),
    ));
    $text = fn (int $i) => is_array($tokens[$i] ?? null) ? $tokens[$i][1] : ($tokens[$i] ?? null);
    $id = fn (int $i) => is_array($tokens[$i] ?? null) ? $tokens[$i][0] : null;
    $skipParens = function (int $i) use ($text): int { // $i at '(' => index after the matching ')'
        for ($depth = 0; ; $i++) {
            $depth += match ($text($i)) {
                '(' => 1, ')' => -1, null => -PHP_INT_MAX, default => 0
            };

            if ($depth <= 0) {
                return $i + 1;
            }
        }
    };
    $calls = [];

    foreach (array_keys($tokens) as $i) {
        $short = in_array($id($i), [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true) ? basename(str_replace('\\', '/', $text($i))) : null;

        if (! isset($ours[$short]) || $id($i + 1) !== T_DOUBLE_COLON || $text($i + 2) !== 'make' || $text($i + 3) !== '(') {
            continue;
        }

        $class = $ours[$short];
        $calls[] = [$class, 'make'];

        for ($j = $skipParens($i + 3); in_array($id($j), [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true) && $id($j + 1) === T_STRING && $text($j + 2) === '('; $j = $skipParens($j + 2)) {
            $calls[] = [$class, $text($j + 1)];
        }
    }

    return $calls;
}

/**
 * Every element of the public surface: id (as printed and as used in the exemptions) => mention pattern.
 *
 * @return array<string, string>
 */
function mlCoverageSurface(): array
{
    $surface = [];

    foreach (mlCoveragePublicClasses() as $class) {
        $surface['class '.$class] = '/(?<![\w\\\\])'.preg_quote($class, '/').'(?![\w\\\\])/';
    }

    foreach (array_keys(mlCoverageStableApi()) as $class) {
        $short = class_basename($class);

        foreach (array_unique([...mlCoverageStableApi()[$class], ...mlCoverageDeclaredStableMethods($class)]) as $method) {
            $surface["member {$short}::{$method}"] = $method === 'make'
                ? '/(?<![\w\\\\])'.$short.'::make\s*\(/'
                : '/(?:::'.$method.'\b|(?<![\w$])'.$method.'\s*\()/';
        }
    }

    foreach (glob(mlCoveragePath('docs/guide/en/*.md')) as $page) {
        $path = 'docs/guide/en/'.basename($page);
        $surface['guide '.$path] = '/'.preg_quote('vendor/maxcxam/larsadmin-medialibrary/'.$path, '/').'/';
    }

    return $surface;
}

it('keeps the frozen public surface of ADR-042: only Media and MediaGallery are public', function () {
    $exemptions = mlCoverageExemptions();
    $violations = [];

    foreach (mlCoverageSourceClasses() as $class => $file) {
        if (array_key_exists('invariant '.$class, $exemptions)) {
            continue;
        }

        $internal = mlCoverageDeclaresInternal(mlCoveragePath($file));
        $public = array_key_exists($class, mlCoverageStableApi());

        if ($internal === null) {
            $violations[] = "  - invariant {$class}: {$file} declares no class, trait, interface or enum";
        } elseif ($internal && $public) {
            $violations[] = "  - invariant {$class}: {$file} is stable API (ADR-042) but marked @internal";
        } elseif (! $internal && ! $public) {
            $violations[] = "  - invariant {$class}: {$file} is not stable API (ADR-042); add @internal to the class docblock";
        }
    }

    expect($violations)->toBe([], implode("\n", $violations));
});

it('declares exactly the stable methods of ADR-042', function () {
    $problems = [];

    foreach (mlCoverageStableApi() as $class => $stable) {
        $short = class_basename($class);
        $declared = mlCoverageDeclaredStableMethods($class);

        foreach (array_diff($stable, $declared) as $method) {
            $problems[] = "  - {$short}::{$method}() is stable API (ADR-042) but missing or @internal in src/";
        }

        foreach (array_diff($declared, $stable) as $method) {
            $problems[] = "  - {$short}::{$method}() is public without @internal; mark it @internal or add it to the stable API (and the skill)";
        }
    }

    expect($problems)->toBe([], implode("\n", $problems));
});

it('mentions every element of the public surface in the skill', function () {
    $text = mlCoverageSkillText();
    $exemptions = mlCoverageExemptions();

    expect($text)->not->toBe('', 'No skill found in resources/boost/skills/.');

    $missing = [];

    foreach (mlCoverageSurface() as $id => $pattern) {
        if (! array_key_exists($id, $exemptions) && preg_match($pattern, $text) !== 1) {
            $missing[] = "  - {$id}";
        }
    }

    expect($missing)->toBe([], sprintf(
        "%d element(s) are not mentioned in the larsadmin-medialibrary skill (classes: the FQCN; members: Class::member,\n"
        ."->member( or member(; guide pages: vendor/maxcxam/larsadmin-medialibrary/docs/guide/en/NAME.md).\n"
        ."Mention them, or (with a reason) add them to tests/Ai/coverage-exemptions.php:\n%s",
        count($missing), implode("\n", $missing),
    ));
});

it('only references API and files that exist from the skill and the guideline', function () {
    $coreApi = is_file(mlCoverageCorePath('kit/public-api.json'))
        ? json_decode(file_get_contents(mlCoverageCorePath('kit/public-api.json')), true, flags: JSON_THROW_ON_ERROR)['php']
        : null;
    $stale = [];

    foreach (mlCoverageAllAiFiles() as $file => $text) {
        // Classes of the add-on: they exist and are not @internal.
        preg_match_all('/Maxcxam\\\\LarsadminMedialibrary(?:\\\\[A-Za-z_][A-Za-z0-9_]*)+/', $text, $m);
        foreach (array_unique($m[0]) as $class) {
            $relative = str_replace('\\', '/', substr($class, strlen('Maxcxam\\LarsadminMedialibrary\\')));
            $source = mlCoveragePath("src/{$relative}.php");

            if (! is_file($source) && ! is_dir(mlCoveragePath("src/{$relative}"))) {
                $stale[] = "  - {$file}: class or namespace {$class} does not exist";
            } elseif (is_file($source) && mlCoverageDeclaresInternal($source)) {
                $stale[] = "  - {$file}: {$class} is @internal, not public API";
            }
        }

        // Classes of the core: public API of the installed core (kit/public-api.json) or one of its namespaces.
        preg_match_all('/Maxcxam\\\\Larsadmin(?:\\\\[A-Za-z_][A-Za-z0-9_]*)+/', $text, $m);
        foreach (array_unique($m[0]) as $class) {
            $namespace = is_dir(mlCoverageCorePath('src/'.str_replace('\\', '/', substr($class, strlen('Maxcxam\\Larsadmin\\')))));

            if ($coreApi !== null && ! isset($coreApi[$class]) && ! $namespace) {
                $stale[] = "  - {$file}: {$class} is not public API of maxcxam/larsadmin (kit/public-api.json)";
            }
        }

        // Media::x / MediaGallery::x in prose (code spans, cheat sheet) and fluent chains in PHP blocks.
        $prose = preg_replace('/^(`{3,}|~{3,}).*?^\1\s*$/ms', '', $text);
        $blocks = preg_match_all('/^```php\R(.*?)^```\s*$/ms', $text, $b) ? $b[1] : [];
        $calls = [];

        preg_match_all('/(?<![\w\\\\])(Media|MediaGallery)::([A-Za-z_]\w*)/', $prose, $m, PREG_SET_ORDER);
        foreach ($m as [, $short, $method]) {
            if ($method !== 'class') {
                $calls[] = [$short === 'Media' ? Media::class : MediaGallery::class, $method];
            }
        }

        foreach ($blocks as $code) {
            array_push($calls, ...mlCoverageChains($code));
        }

        // API cheat sheet rows: every `method(` code span in the first cell is a method of Media (or the named class).
        if (preg_match('/^## API cheat sheet\R(.*?)(?=^## )/ms', $text, $section)) {
            foreach (preg_split('/\R/', $section[1]) as $row) {
                if (! preg_match('/^\|([^|]+)\|/', $row, $cell)) {
                    continue;
                }

                preg_match_all('/`(?:(Media|MediaGallery)::)?([a-z][A-Za-z0-9_]*)\(/', $cell[1], $m, PREG_SET_ORDER);
                foreach ($m as [, $short, $method]) {
                    $calls[] = [$short === 'MediaGallery' ? MediaGallery::class : Media::class, $method];
                }
            }
        }

        foreach (array_unique(array_map(fn (array $call) => implode('::', $call), $calls)) as $call) {
            [$class, $method] = explode('::', $call);

            if (($problem = mlCoverageMethodProblem($class, $method)) !== null) {
                $stale[] = "  - {$file}: ".class_basename($class)."::{$method}() {$problem}";
            }
        }

        // Paths into the installed packages: the add-on's own files and the core's guide pages.
        preg_match_all('#vendor/maxcxam/larsadmin-medialibrary/([\w./-]*[\w/])#', $text, $m);
        foreach (array_unique($m[1]) as $path) {
            if (! file_exists(mlCoveragePath($path))) {
                $stale[] = "  - {$file}: vendor/maxcxam/larsadmin-medialibrary/{$path} does not exist in this package";
            }
        }

        preg_match_all('#vendor/maxcxam/larsadmin/(docs/[\w./-]*[\w/])#', $text, $m);
        foreach (array_unique($m[1]) as $path) {
            if (is_dir(mlCoverageCorePath('docs')) && ! file_exists(mlCoverageCorePath($path))) {
                $stale[] = "  - {$file}: vendor/maxcxam/larsadmin/{$path} does not exist in the installed core";
            }
        }
    }

    expect($stale)->toBe([], sprintf(
        "%d stale reference(s) in the skill or the guideline (renamed, removed or internal API). Fix or remove them:\n%s",
        count($stale), implode("\n", $stale),
    ));
});

it('keeps every coverage exemption justified and current', function () {
    $current = [
        ...array_keys(mlCoverageSurface()),
        ...array_map(fn (string $class) => 'invariant '.$class, array_keys(mlCoverageSourceClasses())),
    ];
    $problems = [];

    foreach (mlCoverageExemptions() as $element => $reason) {
        if (! is_string($reason) || trim($reason) === '') {
            $problems[] = "  - {$element}: no reason given";
        }

        if (! in_array($element, $current, true)) {
            $problems[] = "  - {$element}: not an element of the current surface (remove the exemption)";
        }
    }

    expect($problems)->toBe([], "tests/Ai/coverage-exemptions.php:\n".implode("\n", $problems));
});
