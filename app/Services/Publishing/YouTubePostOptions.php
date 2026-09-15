<?php

declare(strict_types=1);

namespace App\Services\Publishing;

use App\Models\PostTarget;
use Closure;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class YouTubePostOptions
{
    public const array FIELDS = ['privacy_status', 'category_id', 'format_intent', 'made_for_kids', 'contains_synthetic_media', 'has_paid_product_placement', 'notify_subscribers', 'title', 'description', 'thumbnail_media_id'];

    public const array BOOLEAN_FIELDS = ['made_for_kids', 'contains_synthetic_media', 'has_paid_product_placement', 'notify_subscribers'];

    /** @return array<string, mixed> */
    public static function draftRules(): array
    {
        $prefix = 'targets.*.content_override.youtube';

        return [
            $prefix => ['nullable', 'array:'.implode(',', self::FIELDS)],
            $prefix.'.thumbnail_media_id' => ['nullable', 'uuid'],
            $prefix.'.privacy_status' => ['string', Rule::in(['private', 'unlisted', 'public'])],
            $prefix.'.category_id' => ['string', 'regex:/^\d{1,3}$/D'],
            $prefix.'.format_intent' => ['string', Rule::in(['video', 'short'])],
            $prefix.'.title' => ['filled', 'string', static function (string $attribute, mixed $value, Closure $fail): void {
                if (! self::validTitle($value)) {
                    $fail('The YouTube title must contain 1–100 UTF-8 characters without angle brackets.');
                }
            }],
            $prefix.'.description' => ['nullable', 'string', static function (string $attribute, mixed $value, Closure $fail): void {
                if (! self::validDescription($value)) {
                    $fail('The YouTube description must contain at most 5000 UTF-8 bytes without angle brackets.');
                }
            }],
            ...array_fill_keys(array_map(static fn (string $field): string => $prefix.'.'.$field, self::BOOLEAN_FIELDS), ['boolean:strict']),
        ];
    }

    /** @return array{privacy_status: string, category_id: string, format_intent: string, made_for_kids: bool, contains_synthetic_media: bool, has_paid_product_placement: bool, notify_subscribers: bool, title?: string, description?: string, thumbnail_media_id?: string|null}|null */
    public function resolve(PostTarget $target): ?array
    {
        $override = $target->content_override ?? [];
        $options = $override['youtube'] ?? [
            'privacy_status' => (string) config('services.youtube.privacy_status'),
            'category_id' => (string) config('services.youtube.category_id'),
            'format_intent' => (string) config('services.youtube.format_intent'),
            'made_for_kids' => config('services.youtube.made_for_kids'),
            'contains_synthetic_media' => config('services.youtube.contains_synthetic_media'),
            'has_paid_product_placement' => config('services.youtube.has_paid_product_placement'),
            'notify_subscribers' => config('services.youtube.notify_subscribers'),
        ];
        if (! is_array($options) || array_diff_key($options, array_fill_keys(self::FIELDS, true)) !== []) {
            return null;
        }

        if (! in_array($options['privacy_status'] ?? null, ['private', 'unlisted', 'public'], true)
            || ! is_string($options['category_id'] ?? null)
            || ! preg_match('/^\d{1,3}$/D', $options['category_id'])
            || ! in_array($options['format_intent'] ?? null, ['video', 'short'], true)) {
            return null;
        }
        foreach (self::BOOLEAN_FIELDS as $field) {
            if (! is_bool($options[$field] ?? null)) {
                return null;
            }
        }
        if ((array_key_exists('title', $options) && ! self::validTitle($options['title']))
            || (array_key_exists('description', $options) && ! self::validDescription($options['description']))
            || (isset($options['thumbnail_media_id']) && (! is_string($options['thumbnail_media_id']) || ! Str::isUuid($options['thumbnail_media_id'])))) {
            return null;
        }

        $resolved = [
            'privacy_status' => $options['privacy_status'],
            'category_id' => $options['category_id'],
            'format_intent' => $options['format_intent'],
            'made_for_kids' => $options['made_for_kids'],
            'contains_synthetic_media' => $options['contains_synthetic_media'],
            'has_paid_product_placement' => $options['has_paid_product_placement'],
            'notify_subscribers' => $options['notify_subscribers'],
        ];
        if (array_key_exists('title', $options)) {
            $resolved['title'] = $options['title'];
        }
        if (array_key_exists('description', $options)) {
            // Laravel converts an explicitly blank request string to null.
            $resolved['description'] = $options['description'] ?? '';
        }

        if (array_key_exists('thumbnail_media_id', $options)) {
            $resolved['thumbnail_media_id'] = $options['thumbnail_media_id'];
        }

        return $resolved;
    }

    private static function validTitle(mixed $value): bool
    {
        return is_string($value) && mb_check_encoding($value, 'UTF-8') && trim($value) !== ''
            && mb_strlen($value, 'UTF-8') <= 100 && ! str_contains($value, '<') && ! str_contains($value, '>');
    }

    private static function validDescription(mixed $value): bool
    {
        return $value === null || (is_string($value) && mb_check_encoding($value, 'UTF-8')
            && strlen($value) <= 5000 && ! str_contains($value, '<') && ! str_contains($value, '>'));
    }
}
