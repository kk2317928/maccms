<?php

namespace app\common\util;

use DomainException;
use InvalidArgumentException;

final class VodWorkflow
{
    public const IMPORTED = 'imported';
    public const AI_PROCESSING = 'ai_processing';
    public const DUPLICATE_REVIEW = 'duplicate_review';
    public const TMDB_MATCHING = 'tmdb_matching';
    public const MANUAL_REVIEW = 'manual_review';
    public const PUBLISHED = 'published';
    public const REJECTED = 'rejected';
    public const FAILED = 'failed';
    public const MERGED = 'merged';

    private const TRANSITIONS = [
        self::IMPORTED => [self::AI_PROCESSING],
        self::AI_PROCESSING => [self::DUPLICATE_REVIEW, self::FAILED],
        self::DUPLICATE_REVIEW => [self::TMDB_MATCHING, self::MERGED],
        self::TMDB_MATCHING => [self::MANUAL_REVIEW, self::FAILED],
        self::MANUAL_REVIEW => [self::PUBLISHED, self::REJECTED],
        self::PUBLISHED => [],
        self::REJECTED => [],
        self::FAILED => [self::IMPORTED],
        self::MERGED => [],
    ];

    public static function canTransition(string $from, string $to): bool
    {
        self::assertKnownState($from);
        self::assertKnownState($to);

        return in_array($to, self::TRANSITIONS[$from], true);
    }

    public static function assertTransition(string $from, string $to): void
    {
        if (!self::canTransition($from, $to)) {
            throw new DomainException("Forbidden video workflow transition: {$from} -> {$to}.");
        }
    }

    public static function nextAfterRetry(string $failedStage): string
    {
        if ($failedStage !== self::AI_PROCESSING && $failedStage !== self::TMDB_MATCHING) {
            throw new InvalidArgumentException("Unsupported failed workflow stage: {$failedStage}.");
        }

        return self::IMPORTED;
    }

    private static function assertKnownState(string $state): void
    {
        if (!array_key_exists($state, self::TRANSITIONS)) {
            throw new InvalidArgumentException("Unknown video workflow state: {$state}.");
        }
    }
}
