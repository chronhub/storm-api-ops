<?php

declare(strict_types=1);

namespace Storm\ApiOps\Error;

use RuntimeException;

/**
 * A snapshotable fold refused before it ran, for one of two reasons; a 422 by declaration either way.
 *
 * The CEILING: the aggregate's stream head exceeds what an HTTP GET may replay, since a stale or
 * absent snapshot would make the fold as long as the stream. The head bounds the WORST fold, so the
 * refusal is a generous approximation, never a claim about the actual snapshot's freshness; the
 * refusal points at the history the fold is made of, no `storm:*` verb offering
 * an aggregate fold.
 *
 * PERSONAL DATA IN STATE: the stream carries a `#[Personal]` event, and a fold sees decrypted values.
 * Serving `toSnapshot()` here would publish the subject's personal state in clear over HTTP, which is
 * the same artifact `PersonalDataSnapshotGuard` refuses to let a sweep write to disk. Refusing loudly
 * rather than answering a silent `state: null`: that answer is indistinguishable from a
 * non-snapshotable aggregate's, and an operator reading it would conclude the aggregate holds no
 * state.
 */
final class AggregateFoldRefused extends RuntimeException
{
    public static function tooLong(string $category, string $id, int $headVersion, int $ceiling): self
    {
        return new self(sprintf(
            'Refusing to fold aggregate "%s-%s" over HTTP: its stream head is at version %d, past the %d ceiling an HTTP GET may replay when the snapshot is stale or absent. Read its history with storm:events:inspect --stream, or take a snapshot so the fold starts from it.',
            $category,
            $id,
            $headVersion,
            $ceiling,
        ));
    }

    /**
     * @param  string  $offendingType  the stored alias that makes the stream PII-bearing, named so the
     *                                 operator knows WHICH event to look at rather than that one exists
     */
    public static function personalDataInState(string $category, string $id, string $offendingType): self
    {
        return new self(sprintf(
            'Refusing to fold aggregate "%s-%s" over HTTP: its stream carries "%s", an event declared #[Personal], and a fold renders those keys DECRYPTED. Serving its state here would publish in clear what the store ciphers per subject, and no forget could reach an HTTP response. Read the stream through the events feed, which veils.',
            $category,
            $id,
            $offendingType,
        ));
    }
}
