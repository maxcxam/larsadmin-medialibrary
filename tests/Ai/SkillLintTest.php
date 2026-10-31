<?php

use Symfony\Component\Yaml\Yaml;

/*
 * ADR-046, design §6: the shape of the add-on's Boost guideline and skill. Adapted from the core's
 * tests/Ai/SkillLintTest.php (not shipped in vendor/); the forbidden-words list is copied verbatim. The
 * frontmatter is parsed with symfony/yaml, the parser Laravel Boost uses.
 */

const ML_SKILL = 'larsadmin-medialibrary';

function mlLintPath(string $path = ''): string
{
    return str_replace('\\', '/', dirname(__DIR__, 2)).($path === '' ? '' : '/'.$path);
}

/** @return list<string> the mandatory SKILL.md sections, in order (the core's template, design 1.6 §3.3) */
function mlLintSections(): array
{
    return ['When to use', 'Quick start', 'Recipes', 'API cheat sheet', 'Security rules', 'Pitfalls', 'Reference'];
}

/**
 * Pattern => what it catches. Misspellings of the package name and the names of other admin panels (F-52).
 * Copied verbatim from the core.
 *
 * @return array<string, string>
 */
function mlLintForbiddenWords(): array
{
    return [
        '/\bLarsAdmin\b/' => 'misspelled package name (write "Larsadmin")',
        '/\bLARSADMIN\b/' => 'misspelled package name (write "Larsadmin")',
        '/\blars[ _-]admin\b/i' => 'misspelled package name (write "Larsadmin")',
        '/\b(?:larsadmine|larsadmn|larsamdin|larasadmin|laraadmin|lrsadmin)\b/i' => 'misspelled package name (write "Larsadmin")',
        '/\blaravel-admin\b/i' => 'name of another admin package',
        '/\b(?:nova|filament|backpack|orchid|voyager|moonshine|twill)\b/i' => 'name of another admin panel',
    ];
}

/**
 * Lines outside fenced code blocks (line number => text) and the fenced blocks themselves.
 *
 * @return array{0: array<int, string>, 1: list<array{lang: string, line: int, code: string}>}
 */
function mlLintMarkdown(string $text): array
{
    $prose = [];
    $blocks = [];
    $fence = null;

    foreach (preg_split('/\R/', $text) as $index => $line) {
        $number = $index + 1;

        if ($fence === null && preg_match('/^(\s*)(`{3,}|~{3,})\s*([\w+-]*)/', $line, $m)) {
            $fence = ['indent' => strlen($m[1]), 'marker' => $m[2], 'lang' => strtolower($m[3]), 'line' => $number, 'lines' => []];
        } elseif ($fence !== null && preg_match('/^\s*'.preg_quote($fence['marker'], '/').'\s*$/', $line)) {
            $blocks[] = [
                'lang' => $fence['lang'],
                'line' => $fence['line'],
                'code' => implode("\n", array_map(fn (string $l) => substr($l, min($fence['indent'], strspn($l, ' '))), $fence['lines'])),
            ];
            $fence = null;
        } elseif ($fence !== null) {
            $fence['lines'][] = $line;
        } else {
            $prose[$number] = $line;
        }
    }

    return [$prose, $blocks];
}

/** @return array{0: array<string, mixed>, 1: string, 2: string} frontmatter, body and raw frontmatter of a SKILL.md */
function mlLintFrontmatter(string $text): array
{
    if (! preg_match('/\A---\R(.*?)\R---\R(.*)\z/s', $text, $m)) {
        throw new RuntimeException('SKILL.md must start with a YAML frontmatter block between --- lines.');
    }

    $data = Yaml::parse($m[1]);

    if (! is_array($data)) {
        throw new RuntimeException('The frontmatter is not a YAML mapping.');
    }

    return [$data, $m[2], $m[1]];
}

/** @return Generator<string, array{0: string}> package-relative path of the guideline and every skill file */
function mlLintAiFiles(): Generator
{
    $files = [
        ...glob(mlLintPath('resources/boost/guidelines/*.md')),
        ...glob(mlLintPath('resources/boost/skills/*/SKILL.md')),
        ...glob(mlLintPath('resources/boost/skills/*/references/*.md')),
    ];

    foreach ($files as $file) {
        $relative = substr(str_replace('\\', '/', $file), strlen(mlLintPath()) + 1);

        yield $relative => [$relative];
    }
}

/** The major version of the add-on from composer.json's branch alias (dev-main => 1.x-dev). */
function mlLintMajorVersion(): string
{
    $composer = json_decode(file_get_contents(mlLintPath('composer.json')), true, flags: JSON_THROW_ON_ERROR);

    return explode('.', (string) ($composer['extra']['branch-alias']['dev-main'] ?? '1.x-dev'))[0];
}

it('ships exactly the larsadmin-medialibrary skill and one guideline', function () {
    $directories = array_map('basename', glob(mlLintPath('resources/boost/skills/*'), GLOB_ONLYDIR));

    expect($directories)->toBe([ML_SKILL])
        ->and(mlLintPath('resources/boost/skills/'.ML_SKILL.'/SKILL.md'))->toBeFile()
        ->and(array_map('basename', glob(mlLintPath('resources/boost/guidelines/*.md'))))->toBe(['core.md']);
});

it('has valid frontmatter and the section template', function () {
    $file = 'resources/boost/skills/'.ML_SKILL.'/SKILL.md';
    $text = file_get_contents(mlLintPath($file));
    [$meta, $body, $raw] = mlLintFrontmatter($text);

    expect($meta['name'] ?? null)->toBe(ML_SKILL, "{$file}: frontmatter name must equal the directory name")
        ->and($meta['description'] ?? null)->toBeString("{$file}: frontmatter description is required")
        ->and($raw)->toMatch('/^description:\s*"/m', "{$file}: quote the description (colons and # break plain YAML scalars)");

    $description = $meta['description'];

    expect(trim($description))->not->toBe('', "{$file}: description is empty")
        ->and(mb_strlen($description))->toBeLessThanOrEqual(1024, "{$file}: description is longer than 1024 characters")
        ->and($description)->toMatch('/\bUse when\b/', "{$file}: description must read \"What it covers. Use when ...\"")
        ->and($description)->toMatch('#\bmaxcxam/larsadmin-medialibrary\b#', "{$file}: description must name the package")
        ->and($meta['license'] ?? null)->toBe('MIT', "{$file}: license must be MIT")
        ->and($meta['metadata']['package'] ?? null)->toBe('maxcxam/larsadmin-medialibrary', "{$file}: metadata.package")
        ->and($meta['metadata']['version'] ?? null)->toBeString("{$file}: metadata.version must be a quoted string such as \"1.0\"")
        ->and((string) ($meta['metadata']['version'] ?? ''))->toMatch('/^'.mlLintMajorVersion().'\.\d+$/', "{$file}: metadata.version must be the add-on's MAJOR.MINOR");

    [$prose] = mlLintMarkdown($body);
    $headings = array_values(array_filter($prose, fn (string $line) => preg_match('/^#{1,2} /', $line) === 1));

    expect($headings[0] ?? null)->toStartWith('# ', "{$file}: the body must start with a # title");

    $sections = array_map(fn (string $line) => trim(substr($line, 3)), array_filter($headings, fn (string $line) => str_starts_with($line, '## ')));
    $template = array_values(array_filter($sections, fn (string $section) => in_array($section, mlLintSections(), true)));

    expect($template)->toBe(mlLintSections(), "{$file}: the ## sections ".implode(', ', mlLintSections()).' must each appear once, in this order')
        ->and(count(preg_split('/\R/', rtrim($text))))->toBeLessThanOrEqual(400, "{$file}: SKILL.md is longer than 400 lines; move long tables to references/*.md");
});

it('keeps the guideline short and routes to the skill', function () {
    $file = 'resources/boost/guidelines/core.md';
    $text = file_get_contents(mlLintPath($file));

    expect(count(preg_split('/\R/', rtrim($text))))->toBeLessThanOrEqual(30, "{$file} is longer than 30 lines (design §6)")
        ->and($text)->toMatch('/(?<![\w-])'.preg_quote(ML_SKILL, '/').'(?![\w-])/', "{$file} must route to the skill ".ML_SKILL);
});

it('is written in English without forbidden words', function (string $file) {
    $problems = [];

    foreach (preg_split('/\R/', file_get_contents(mlLintPath($file))) as $index => $line) {
        if (preg_match('/\p{Cyrillic}/u', $line)) {
            $problems[] = sprintf('  - line %d: Cyrillic text (skills are English only)', $index + 1);
        }

        foreach (mlLintForbiddenWords() as $pattern => $reason) {
            if (preg_match($pattern, $line, $m)) {
                $problems[] = sprintf('  - line %d: "%s", %s', $index + 1, $m[0], $reason);
            }
        }
    }

    expect($problems)->toBe([], "{$file}:\n".implode("\n", $problems));
})->with(fn () => mlLintAiFiles());

it('has relative links that resolve', function (string $file) {
    [$prose] = mlLintMarkdown(file_get_contents(mlLintPath($file)));
    $broken = [];

    foreach ($prose as $number => $line) {
        preg_match_all('/\[[^\]]*\]\(\s*<?([^)\s>]+)>?(?:\s+"[^"]*")?\s*\)/', $line, $m);

        foreach ($m[1] as $target) {
            if (preg_match('/^(?:[a-z][a-z0-9+.-]*:|#)/i', $target)) {
                continue; // URL, mailto: or an anchor in the same file
            }

            $path = rawurldecode(explode('#', $target)[0]);

            if (str_starts_with($path, '/')) {
                $broken[] = "  - line {$number}: {$target} is absolute; use a path relative to the file";

                continue;
            }

            if (! file_exists(dirname(mlLintPath($file)).'/'.$path)) {
                $broken[] = "  - line {$number}: {$target} does not exist";
            }
        }
    }

    expect($broken)->toBe([], "{$file}:\n".implode("\n", $broken));
})->with(fn () => mlLintAiFiles());

it('has PHP code blocks that parse', function (string $file) {
    [, $blocks] = mlLintMarkdown(file_get_contents(mlLintPath($file)));
    $errors = [];

    foreach ($blocks as $block) {
        if ($block['lang'] !== 'php') {
            continue;
        }

        $prefixed = ! str_starts_with(ltrim($block['code']), '<?php');
        $code = $prefixed ? "<?php\n".$block['code'] : $block['code'];

        try {
            token_get_all($code, TOKEN_PARSE);
        } catch (ParseError $e) {
            $errors[] = sprintf('  - block at line %d: %s (line %d of the block)', $block['line'], $e->getMessage(), $e->getLine() - ($prefixed ? 1 : 0));
        }
    }

    expect($errors)->toBe([], "{$file}: PHP examples must be valid PHP (statements, not fragments):\n".implode("\n", $errors));
})->with(fn () => mlLintAiFiles());
