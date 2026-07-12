<?php

declare(strict_types=1);

namespace Academe\LaravelJournal\Casts;

use Academe\LaravelJournal\Exceptions\InvalidTags;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Casts the tags JSON column to a flat map of string keys to scalar
 * values. The shape is deliberately opinionated — tags are labels, not
 * a document store — so writes reject lists, nested arrays, and
 * objects.
 *
 * Reads always return an array (a stored NULL reads as []), and an
 * empty tag set is stored as NULL. Reads are lenient: entries that
 * don't fit the shape (e.g. hand-written rows) are dropped rather than
 * thrown on.
 *
 * @implements CastsAttributes<array<string, bool|int|float|string>, mixed>
 */
class TagsCast implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, bool|int|float|string>
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null) {
            return [];
        }

        $decoded = json_decode((string) $value, true);

        if (! is_array($decoded)) {
            return [];
        }

        $tags = [];

        foreach ($decoded as $tagKey => $tagValue) {
            if (is_string($tagKey) && is_scalar($tagValue)) {
                $tags[$tagKey] = $tagValue;
            }
        }

        return $tags;
    }

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws InvalidTags
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === []) {
            return null;
        }

        if (! is_array($value)) {
            throw new InvalidTags(sprintf(
                'Tags must be a map of string keys to scalar values, %s given.',
                get_debug_type($value),
            ));
        }

        foreach ($value as $tagKey => $tagValue) {
            if (! is_string($tagKey)) {
                throw new InvalidTags(sprintf(
                    "Tag keys must be strings, key %s is an integer; use a map ('status' => 'paid'), not a list.",
                    $tagKey,
                ));
            }

            if (! is_scalar($tagValue)) {
                throw new InvalidTags(sprintf(
                    "Tag '%s' must have a scalar value, %s given.",
                    $tagKey,
                    get_debug_type($tagValue),
                ));
            }
        }

        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}
