<?php

namespace App\Support;

use App\Models\Mission;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * FR-SDT-012: a Ministry HQ Officer's configured portfolio of missions,
 * countries and sectors (users.portfolio). A record is in the portfolio
 * when it matches ANY configured dimension:
 *
 *  - alerts: their mission, their country, or a sector keyword found in
 *    their sector or product category;
 *  - inquiries: their mission, that mission's host country, or a sector
 *    keyword found in their product or sector;
 *  - directives and periodic reports: their mission or that mission's
 *    host country (they carry no sector).
 *
 * Countries compare case-insensitively. A sector keyword matches as a
 * whole word or phrase ("tea" matches "Tea (re-export hub)" but not
 * "steam"), because both sector fields are free text. The portfolio is a
 * default view, never an access boundary: callers confine every query to
 * the officer's own department separately (NFR-SEC-006).
 */
final class HqPortfolio
{
    public const int MAX_MISSIONS = 50;

    public const int MAX_COUNTRIES = 50;

    public const int MAX_SECTORS = 30;

    public const int MAX_COUNTRY_LENGTH = 100;

    public const int MAX_SECTOR_LENGTH = 60;

    /**
     * A sector keyword starts and ends with a letter or digit; inside it
     * may also hold spaces, ampersands, apostrophes, slashes and hyphens.
     */
    public const string SECTOR_PATTERN = "/^[\pL\pN](?:[\pL\pN &'\/-]*[\pL\pN])?$/u";

    /**
     * A country name: letters, digits, spaces and the punctuation real names
     * use ("Côte d’Ivoire", "Korea, Republic of", "Bosnia and Herzegovina").
     */
    public const string COUNTRY_PATTERN = "/^[\pL\pN][\pL\pN .,'’()&-]*$/u";

    /**
     * @param  array<int, string>  $missionIds
     * @param  array<int, string>  $countries
     * @param  array<int, string>  $sectors
     */
    private function __construct(
        public readonly array $missionIds,
        public readonly array $countries,
        public readonly array $sectors,
    ) {}

    public static function forUser(User $user): self
    {
        return self::fromStored($user->portfolio);
    }

    /**
     * Rebuilds a portfolio from users.portfolio, tolerating a missing or
     * malformed value (an empty portfolio, never an error).
     */
    public static function fromStored(mixed $stored): self
    {
        if (! is_array($stored)) {
            return new self([], [], []);
        }

        return self::fromInput(
            self::stringList($stored['mission_ids'] ?? []),
            self::stringList($stored['countries'] ?? []),
            self::stringList($stored['sectors'] ?? []),
        );
    }

    /**
     * Normalises validated input: trims and squashes whitespace, drops
     * blanks, removes case-insensitive duplicates (keeping the first
     * spelling) and keeps only well-formed mission ids.
     *
     * @param  array<int, string>  $missionIds
     * @param  array<int, string>  $countries
     * @param  array<int, string>  $sectors
     */
    public static function fromInput(array $missionIds, array $countries, array $sectors): self
    {
        $missionIds = collect($missionIds)
            ->map(fn (string $id): string => Str::lower(trim($id)))
            ->filter(fn (string $id): bool => Str::isUuid($id))
            ->unique()
            ->values()
            ->all();

        return new self($missionIds, self::distinctLabels($countries), self::distinctLabels($sectors));
    }

    public function isEmpty(): bool
    {
        return $this->missionIds === [] && $this->countries === [] && $this->sectors === [];
    }

    /**
     * Whether any mission-based record (a directive or a report) can ever
     * fall inside this portfolio.
     */
    public function hasGeography(): bool
    {
        return $this->missionIds !== [] || $this->countries !== [];
    }

    /**
     * @return array{mission_ids: array<int, string>, countries: array<int, string>, sectors: array<int, string>}|null
     */
    public function toStorage(): ?array
    {
        return $this->isEmpty() ? null : [
            'mission_ids' => $this->missionIds,
            'countries' => $this->countries,
            'sectors' => $this->sectors,
        ];
    }

    /**
     * Narrows an Alert query to this portfolio. An empty portfolio matches
     * nothing, so a caller that forgets to check isEmpty() fails closed.
     */
    public function constrainAlerts(Builder $query): void
    {
        $query->where(function (Builder $inner): void {
            $inner->whereRaw('1 = 0');

            if ($this->missionIds !== []) {
                $inner->orWhereIn($inner->qualifyColumn('mission_id'), $this->missionIds);
            }

            if ($this->countries !== []) {
                $inner->orWhereIn(DB::raw('lower('.$inner->qualifyColumn('country').')'), $this->lowerCountries());
            }

            if ($this->sectors !== []) {
                $pattern = $this->sectorPattern();
                $inner->orWhereRaw("coalesce({$inner->qualifyColumn('sector')}, '') ~* ?", [$pattern])
                    ->orWhereRaw("coalesce({$inner->qualifyColumn('product_category')}, '') ~* ?", [$pattern]);
            }
        });
    }

    public function constrainInquiries(Builder $query): void
    {
        $query->where(function (Builder $inner): void {
            $inner->whereRaw('1 = 0');
            $this->orInGeography($inner);

            if ($this->sectors !== []) {
                $inner->orWhereRaw("coalesce({$inner->qualifyColumn('product_or_sector')}, '') ~* ?", [$this->sectorPattern()]);
            }
        });
    }

    /**
     * Directives and periodic reports: mission or the mission's country.
     */
    public function constrainMissionRecords(Builder $query): void
    {
        $query->where(function (Builder $inner): void {
            $inner->whereRaw('1 = 0');
            $this->orInGeography($inner);
        });
    }

    /**
     * Whether a mission (by id and host country) is in this portfolio.
     */
    public function coversMission(string $missionId, ?string $hostCountry): bool
    {
        return in_array(Str::lower($missionId), $this->missionIds, true)
            || ($hostCountry !== null && in_array(Str::lower($hostCountry), $this->lowerCountries(), true));
    }

    private function orInGeography(Builder $inner): void
    {
        if ($this->missionIds !== []) {
            $inner->orWhereIn($inner->qualifyColumn('mission_id'), $this->missionIds);
        }

        if ($this->countries !== []) {
            $inner->orWhereIn($inner->qualifyColumn('mission_id'), Mission::query()
                ->whereIn(DB::raw('lower(host_country)'), $this->lowerCountries())
                ->select('id'));
        }
    }

    /**
     * @return array<int, string>
     */
    private function lowerCountries(): array
    {
        return array_map(fn (string $country): string => Str::lower($country), $this->countries);
    }

    /**
     * A PostgreSQL ARE matching any keyword as a whole word or phrase.
     * \m and \M anchor at word starts and ends; every character that is
     * not a letter, digit or space is escaped, so a keyword is always
     * literal text, never a pattern.
     */
    private function sectorPattern(): string
    {
        $alternatives = array_map(
            fn (string $sector): string => (string) preg_replace('/[^\pL\pN ]/u', '\\\\$0', $sector),
            $this->sectors,
        );

        return '\m(?:'.implode('|', $alternatives).')\M';
    }

    /**
     * @param  array<int, string>  $labels
     * @return array<int, string>
     */
    private static function distinctLabels(array $labels): array
    {
        return collect($labels)
            ->map(fn (string $label): string => Str::squish($label))
            ->filter(fn (string $label): bool => $label !== '')
            ->unique(fn (string $label): string => Str::lower($label))
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private static function stringList(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }
}
