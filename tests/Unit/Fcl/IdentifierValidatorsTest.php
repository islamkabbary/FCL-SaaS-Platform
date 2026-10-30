<?php

use App\Models\Fcl\Party;
use App\Rules\Fcl\Acid;
use App\Rules\Fcl\ContainerNumber;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/*
 * The FCL identifier validators. Pure classes; the application is booted only for the
 * translated messages and the validation-rule integration.
 */

uses(TestCase::class);

test('container numbers are normalised to upper case without grouping spaces and hyphens', function (string $input, string $normalized) {
    expect(ContainerNumber::normalize($input))->toBe($normalized);
})->with([
    'grouped' => ['CSQU 305438 3', 'CSQU3054383'],
    'lower case' => ['csqu3054383', 'CSQU3054383'],
    'hyphen before the check digit' => ['MSKU 907032-3', 'MSKU9070323'],
    'surrounding whitespace and a tab' => ["  CSQU\t3054383 ", 'CSQU3054383'],
    'en dash' => ["MSKU 907032\u{2013}3", 'MSKU9070323'],
    'pasted with direction marks and a zero-width space' => ["\u{200F}CSQU\u{200B}3054383\u{200E}", 'CSQU3054383'],
    'fullwidth characters' => ['ＣＳＱＵ３０５４３８３', 'CSQU3054383'],
]);

test('the ISO 6346 check digit matches published examples', function (string $firstTen, int $digit) {
    expect(ContainerNumber::checkDigit($firstTen))->toBe($digit);
})->with([
    'ISO 6346 example' => ['CSQU305438', 3],
    'Maersk example' => ['MSKU907032', 3],
    'remainder of 10 is written as 0' => ['MSCU000006', 0],
]);

test('valid container numbers are accepted, whatever the category identifier', function (string $number) {
    expect(ContainerNumber::isValid($number))->toBeTrue()
        ->and(Validator::make(['container' => $number], ['container' => new ContainerNumber])->passes())->toBeTrue();
})->with([
    'CSQU3054383',
    'csqu 305438 3',
    'MSKU9070323',
    'MSCU0000060',
    'ABCJ0000010',
    'XYZZ9999992',
]);

// Empty values are left to "required", like every non-implicit rule.
test('malformed container numbers are refused', function (mixed $value) {
    expect(Validator::make(['container' => $value], ['container' => new ContainerNumber])->errors()->first('container'))
        ->toContain('must be a container number');
})->with([
    'too short' => ['CSQU305438'],
    'too long' => ['CSQU30543831'],
    'digits in the owner code' => ['CS1U3054383'],
    'unknown category identifier' => ['CSQX3054383'],
    'letters in the serial' => ['CSQU30543A3'],
    'other punctuation' => ['CSQU.3054383'],
    'not a string' => [['CSQU3054383']],
    'a number' => [3054383],
]);

test('a wrong check digit is refused, unless the override is deliberate', function () {
    expect(ContainerNumber::isValid('CSQU3054384'))->toBeFalse()
        ->and(Validator::make(['container' => 'CSQU3054384'], ['container' => new ContainerNumber])->errors()->first('container'))
        ->toContain('check digit of this container number is wrong')
        ->and(Validator::make(['container' => 'CSQU3054384'], ['container' => ContainerNumber::allowWrongCheckDigit()])->passes())->toBeTrue()
        // The override covers the check digit only, never a malformed number.
        ->and(Validator::make(['container' => 'CSQU305438'], ['container' => ContainerNumber::allowWrongCheckDigit()])->passes())->toBeFalse();
});

test('a check digit can only be computed from four letters and six digits', function (string $firstTen) {
    ContainerNumber::checkDigit($firstTen);
})->throws(InvalidArgumentException::class)->with([
    'too short' => ['CSQU30543'],
    'with a trailing newline' => ["CSQU305438\n"],
]);

test('the shape checks do not accept a trailing newline', function () {
    expect(ContainerNumber::isWellFormed("CSQU3054383\n"))->toBeFalse()
        ->and(ContainerNumber::hasValidCheckDigit("CSQU3054383\n"))->toBeFalse()
        ->and(Acid::isWellFormed("1234567890123456\n"))->toBeFalse()
        ->and(Acid::isWellFormed(''))->toBeFalse();
});

test('ACIDs are normalised: whitespace removed, Arabic-Indic digits written as ASCII digits', function (string $input, string $normalized) {
    expect(Acid::normalize($input))->toBe($normalized);
})->with([
    'grouped with spaces' => ['1234 5678 9012 3456', '1234567890123456'],
    'tabs, newlines and surrounding spaces' => ["\t1234567890123456789\n ", '1234567890123456789'],
    'non-breaking spaces' => ["1234\u{00A0}5678\u{202F}9012\u{2009}3456", '1234567890123456'],
    'Arabic-Indic digits' => ['٠١٢٣٤٥٦٧٨٩٠١٢٣٤٥', '0123456789012345'],
    'Extended Arabic-Indic digits' => ['۰۱۲۳۴۵۶۷۸۹۰۱۲۳۴۵', '0123456789012345'],
    'fullwidth digits' => ['１２３４５６７８９０１２３４５６', '1234567890123456'],
    'pasted with direction marks, a zero-width space and a byte order mark' => ["\u{FEFF}1234\u{200E}5678\u{200B}9012\u{200F}3456", '1234567890123456'],
    'leading zeros are kept' => ['0000 0000 0000 0001', '0000000000000001'],
]);

test('16- and 19-digit ACIDs are both accepted without a warning', function (string $acid) {
    $normalized = Acid::normalize($acid);

    expect(Acid::isWellFormed($normalized))->toBeTrue()
        ->and(Acid::lengthWarning($normalized))->toBeNull()
        ->and(Validator::make(['acid' => $acid], ['acid' => new Acid])->passes())->toBeTrue();
})->with([
    '16 digits' => ['1234567890123456'],
    '19 digits' => ['1234567890123456789'],
    '19 digits, grouped' => ['1234 5678 9012 3456 789'],
]);

test('an ACID of another length is accepted with a warning, never refused', function (string $acid, int $count) {
    expect(Validator::make(['acid' => $acid], ['acid' => new Acid])->passes())->toBeTrue()
        ->and(Acid::lengthWarning(Acid::normalize($acid)))->toContain("has {$count} digits");
})->with([
    'shorter' => ['123456789', 9],
    'between the known lengths' => ['12345678901234567', 17],
    'longer' => ['12345678901234567890', 20],
]);

test('empty and non-digit ACIDs are refused', function (mixed $value) {
    expect(Validator::make(['acid' => $value], ['acid' => ['required', new Acid]])->fails())->toBeTrue();
})->with([
    'empty' => [''],
    'whitespace only' => ['   '],
    'letters' => ['1234ABCD90123456'],
    'hyphens' => ['1234-5678-9012-3456'],
    'a decimal number' => ['1234567890.123456'],
    'a negative number' => ['-1234567890123456'],
    'not a string' => [1234567890123456],
    'an array' => [['1234567890123456']],
]);

test('no warning is given for an ACID that is refused anyway', function () {
    expect(Acid::lengthWarning('12AB'))->toBeNull();
});

// Direct checks of the keys: the database collation alone would hide some of these.
test('the duplicate keys of a party are normalised on their own', function () {
    expect(Party::nameKey('Société  Générale, S.A.'))->toBe('societe generale s a')
        ->and(Party::nameKey('ＡＣＭＥ'))->toBe('acme')
        ->and(Party::nameKey('***'))->toBe('')
        ->and(Party::taxNumberKey('١٢٣-۴۵۶ ab'))->toBe('123456AB')
        ->and(Party::taxNumberKey('---'))->toBe('')
        ->and(mb_strlen(Party::nameKey(str_repeat('ﷺ', 255))))->toBeLessThanOrEqual(255);
});
