<?php

namespace App\Models\Fcl;

use App\Models\User;
use App\Policies\Fcl\PartyPolicy;
use App\Rules\Fcl\Acid;
use Database\Factories\Fcl\PartyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Normalizer;

/**
 * A party of FCL Operations (customer, importer, agent, carrier): master data in the
 * tenant database, shared by every branch of the company. Written only through the
 * audited CreateParty and UpdateParty actions.
 *
 * @property int $id
 * @property string $legal_name
 * @property string $name_key
 * @property string|null $tax_number
 * @property string|null $tax_number_key
 * @property string|null $country_code
 * @property string|null $notes
 * @property bool $is_active
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $creator
 */
#[Fillable(['legal_name', 'tax_number', 'country_code', 'notes', 'is_active'])]
#[UsePolicy(PartyPolicy::class)]
class Party extends Model
{
    /** @use HasFactory<PartyFactory> */
    use HasFactory;

    /**
     * The attributes a user edits, in the order they are audited.
     */
    public const EditableAttributes = ['legal_name', 'tax_number', 'country_code', 'notes', 'is_active'];

    /**
     * Arabic letters often written for one another, folded for the duplicate check only.
     */
    private const ArabicLetterVariants = ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ة' => 'ه', 'ى' => 'ي'];

    /**
     * Default attribute values, matching the column defaults.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
    ];

    protected static function booted(): void
    {
        // The duplicate keys always follow the values they are derived from.
        static::saving(function (self $party): void {
            $party->name_key = self::nameKey($party->legal_name);
            $taxNumberKey = $party->tax_number === null ? '' : self::taxNumberKey($party->tax_number);
            $party->tax_number_key = $taxNumberKey === '' ? null : $taxNumberKey;
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * The name compared for duplicates: compatibility-decomposed (fullwidth letters and
     * Arabic presentation forms become ordinary letters), lower case, without diacritics
     * or the Arabic tatweel, with the Arabic letter variants folded, and the letters and
     * digits of any script separated by single spaces. "ACME Trading Co." and
     * "acme  trading co" give the same key, as do "Société" and "Societe", and
     * "شركة الأمل" and "شَركه الامـل". Never longer than the column.
     */
    public static function nameKey(string $name): string
    {
        if (class_exists(Normalizer::class)) {
            $name = (string) Normalizer::normalize($name, Normalizer::FORM_KD);
        }

        $key = (string) preg_replace('/[\p{M}\x{0640}]+/u', '', Str::lower($name));
        $key = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', strtr($key, self::ArabicLetterVariants));

        return mb_substr(trim($key), 0, 255);
    }

    /**
     * The tax or registration number compared for duplicates: letters and digits only,
     * with fullwidth and Arabic-Indic digits written as ASCII digits, so "123-456-789",
     * "123 456 789" and "١٢٣٤٥٦٧٨٩" match. ASCII upper case only ("ß" would become
     * "SS"). Never longer than the column.
     */
    public static function taxNumberKey(string $taxNumber): string
    {
        if (class_exists(Normalizer::class)) {
            $taxNumber = (string) Normalizer::normalize($taxNumber, Normalizer::FORM_KC);
        }

        $key = strtoupper((string) preg_replace('/[^\p{L}\p{N}]+/u', '', strtr($taxNumber, Acid::AsciiDigits)));

        return mb_substr($key, 0, 50);
    }

    /**
     * Parties that look like the same company: the same name key or the same tax number
     * key. A warning only; the company may knowingly keep both.
     *
     * @return Collection<int, self>
     */
    public static function similarTo(string $legalName, ?string $taxNumber, ?int $ignoreId = null): Collection
    {
        return self::similarToKeys(self::nameKey($legalName), $taxNumber === null ? '' : self::taxNumberKey($taxNumber), $ignoreId);
    }

    /**
     * Parties with the given name key or tax-number key. An empty key matches nothing:
     * with no key at all, an empty condition group would match every party.
     *
     * @return Collection<int, self>
     */
    public static function similarToKeys(string $nameKey, string $taxNumberKey, ?int $ignoreId = null): Collection
    {
        if ($nameKey === '' && $taxNumberKey === '') {
            return new Collection;
        }

        return self::query()
            ->where(fn (Builder $query) => $query
                ->when($nameKey !== '', fn (Builder $query) => $query->orWhere('name_key', $nameKey))
                ->when($taxNumberKey !== '', fn (Builder $query) => $query->orWhere('tax_number_key', $taxNumberKey)))
            ->when($ignoreId !== null, fn (Builder $query) => $query->whereKeyNot($ignoreId))
            ->orderBy('legal_name')
            ->orderBy('id')
            ->limit(10)
            ->get();
    }

    /**
     * The user who created the party.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The audited values of the party.
     *
     * @return array<string, mixed>
     */
    public function auditedValues(): array
    {
        return $this->only(self::EditableAttributes);
    }
}
