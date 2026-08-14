<?php

namespace App\Services;

use App\Models\Inquiry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * CLAUDE.md Section 11 / FR-INQ-019 (Match and Flag Cross-Mission
 * Inquiries): surfaces *suggested* links only (AC1) — never merges or
 * reassigns inquiries between missions (AC2 Notes: "HQ does not reassign
 * inquiries between missions; this requirement provides visibility and
 * linkage only"). Actually persisting a confirmed link is
 * InquiryService::linkInquiry()'s job, not this service's.
 *
 * ISSUE-001 (dispute lifecycle) is unrelated and remains untouched by this
 * service; FR-INQ-019 is cross-mission linking only (CLAUDE.md Section 16).
 */
class InquiryMatchingService
{
    private const int MAX_MATCHES = 10;

    private const int MAX_QUERY_TERMS = 12;

    /**
     * Ranks candidate inquiries in the same ministry (excluding the
     * inquiry's own mission, since same-mission duplicates are not what
     * FR-INQ-019 is for) by relevance, not a definitive merge. Uses the
     * existing inquiries.search_vector tsvector/GIN index (Session 15,
     * FR-SEARCH-001) built from inquirer_name/description/product_or_sector
     * as the primary mechanism, plus an exact inquirer_organisation match
     * (FR-INQ-019 AC1's "buyer" similarity) — inquirer_organisation is not
     * itself part of the generated search_vector column (CLAUDE.md Section
     * 6), so it is matched separately rather than silently dropped.
     *
     * Deliberately OR's each significant term individually rather than
     * running plainto_tsquery() once over the whole concatenated text:
     * plainto_tsquery ANDs every lexeme it's given, so a single combined
     * query built from a full description would require every word of it
     * to appear in a candidate row — in practice never matching anything
     * except a near-duplicate. "Overlaps significantly" (AC1) means ANY of
     * several significant terms in common, ranked by how many/how well
     * they match (ts_rank), not all of them at once.
     *
     * @return Collection<int, Inquiry>
     */
    public function findMatches(Inquiry $inquiry): Collection
    {
        $terms = $this->extractSignificantTerms($inquiry);

        if ($terms->isEmpty() && $inquiry->inquirer_organisation === null) {
            return collect();
        }

        // An OR-combined tsquery: matches a candidate row whose
        // search_vector contains any one of the significant terms.
        $tsqueryExpr = $terms->isEmpty()
            ? "plainto_tsquery('english', '')"
            : implode(' || ', array_fill(0, $terms->count(), "plainto_tsquery('english', ?)"));

        return Inquiry::query()
            ->with('mission')
            ->where('ministry_id', $inquiry->ministry_id)
            ->where('id', '!=', $inquiry->id)
            ->where('mission_id', '!=', $inquiry->mission_id)
            ->selectRaw('inquiries.*')
            ->selectRaw("ts_rank(search_vector, ({$tsqueryExpr})) as rank", $terms->all())
            ->where(function (Builder $builder) use ($tsqueryExpr, $terms, $inquiry): void {
                if ($terms->isNotEmpty()) {
                    $builder->whereRaw("search_vector @@ ({$tsqueryExpr})", $terms->all());
                }

                if ($inquiry->inquirer_organisation !== null) {
                    $builder->orWhereRaw('lower(inquirer_organisation) = lower(?)', [$inquiry->inquirer_organisation]);
                }
            })
            ->orderByDesc('rank')
            ->limit(self::MAX_MATCHES)
            ->get();
    }

    /**
     * Splits product_or_sector + description into lowercased word terms,
     * dropping short (<4 character) words as a cheap stopword filter
     * ("for", "the", "and", ...) without a full stopword list.
     *
     * @return Collection<int, string>
     */
    private function extractSignificantTerms(Inquiry $inquiry): Collection
    {
        $text = trim(implode(' ', array_filter([$inquiry->product_or_sector, $inquiry->description])));

        if ($text === '') {
            return collect();
        }

        return collect(preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY))
            ->map(fn (string $term): string => mb_strtolower($term))
            ->filter(fn (string $term): bool => mb_strlen($term) >= 4)
            ->unique()
            ->take(self::MAX_QUERY_TERMS)
            ->values();
    }
}
