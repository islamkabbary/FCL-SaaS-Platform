<?php

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\SplFileInfo;

/**
 * The source files that may hold user-facing text.
 *
 * @return list<SplFileInfo>
 */
function translatableSourceFiles(): array
{
    return [...File::allFiles(app_path()), ...File::allFiles(resource_path('views'))];
}

/**
 * The literal keys passed to the translation helpers in the application's source.
 *
 * @return list<string>
 */
function translationKeysInSource(): array
{
    $pattern = '/(?:\b__|\btrans_choice|\btrans|@lang)\(\s*(?:\'((?:[^\'\\\\]|\\\\.)*)\'|"((?:[^"\\\\]|\\\\.)*)")/';
    $keys = [];

    foreach (translatableSourceFiles() as $file) {
        preg_match_all($pattern, $file->getContents(), $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $keys[] = stripslashes($match[2] ?? '' ?: $match[1]);
        }
    }

    return array_values(array_unique($keys));
}

/**
 * @return array<string, string>
 */
function arabicTranslations(): array
{
    return json_decode(File::get(lang_path('ar.json')), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * @return list<string>
 */
function placeholdersIn(string $line): array
{
    preg_match_all('/:[a-zA-Z]+/', $line, $matches);
    $placeholders = array_unique($matches[0]);
    sort($placeholders);

    return $placeholders;
}

test('every translation key in the source has an Arabic translation', function () {
    $translations = arabicTranslations();

    $missing = array_filter(
        translationKeysInSource(),
        fn (string $key): bool => ! array_key_exists($key, $translations) && ! trans()->hasForLocale($key, 'ar'),
    );

    expect(array_values($missing))->toBe([]);
});

test('Arabic translations keep the placeholders of their English key', function () {
    $mismatched = array_keys(array_filter(
        arabicTranslations(),
        fn (string $arabic, string $english): bool => placeholdersIn($arabic) !== placeholdersIn($english),
        ARRAY_FILTER_USE_BOTH,
    ));

    expect($mismatched)->toBe([]);
});

test('the framework language files have the same keys in both languages', function (string $file) {
    $keys = fn (string $locale): array => array_keys(Arr::except(Arr::dot(require lang_path("{$locale}/{$file}.php")), ['custom.attribute-name.rule-name']));

    $arabic = array_filter($keys('ar'), fn (string $key): bool => ! str_starts_with($key, 'attributes.'));
    $english = array_filter($keys('en'), fn (string $key): bool => ! str_starts_with($key, 'attributes'));

    expect(array_values(array_diff($english, $arabic)))->toBe([])
        ->and(array_values(array_diff($arabic, $english)))->toBe([]);
})->with(['auth', 'pagination', 'passwords', 'validation']);

test('views use logical direction utilities', function () {
    $physical = '/(?<![\w:-])-?(?:ml|mr|pl|pr)-(?:\d|\[)|\b(?:text-(?:left|right)|float-(?:left|right)|border-[lr]\b|rounded-[lr]-)/';
    $offenders = [];

    foreach (File::allFiles(resource_path('views')) as $file) {
        if ($file->getFilename() === 'welcome.blade.php' || str_contains($file->getPathname(), 'icon')) {
            continue;
        }

        if (preg_match($physical, $file->getContents(), $match) === 1) {
            $offenders[] = $file->getRelativePathname().': '.$match[0];
        }
    }

    expect($offenders)->toBe([]);
});

test('every page document declares its direction', function () {
    $withoutDirection = [];

    foreach (File::allFiles(resource_path('views')) as $file) {
        if (preg_match('/<html\b(?:(?!\bdir=)[^\n])*$/m', $file->getContents()) === 1) {
            $withoutDirection[] = $file->getRelativePathname();
        }
    }

    expect($withoutDirection)->toBe([]);
});
