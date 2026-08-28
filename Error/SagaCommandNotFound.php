<?php

declare(strict_types=1);

namespace Storm\ApiOps\Error;

use RuntimeException;

/**
 * The redrive was aimed at something that is not there: a 404, a wrong address rather than a
 * conflict.
 *
 * Its own error rather than the engine's `WorkflowNotFound`, which says "no workflow registered
 * under X" and would be a lie here twice over: the workflow type is registered, and what is missing
 * is a command row or an instance. An operator reading a 404 body must learn which of the two.
 */
final class SagaCommandNotFound extends RuntimeException
{
    public static function command(string $correlationId, string $messageId): self
    {
        return new self(sprintf(
            'No command "%s" for saga %s: check the message id printed by the saga snapshot, or the outbox row may have been pruned.',
            $messageId,
            $correlationId,
        ));
    }

    /**
     * The saga vanished between the redrive and the read-back. The guards proved it was running when
     * the row flipped, so only a prune racing us explains this; it is reported rather than dressed
     * up as a successful repair with nothing to show.
     */
    public static function instance(string $correlationId): self
    {
        return new self(sprintf(
            'Saga %s vanished between the redrive and reading it back; the command was re-sent, but the instance is gone.',
            $correlationId,
        ));
    }

    /**
     * The same prune race under any other verb: the verb applied, then a retention prune won the
     * read-back. The verb is not the deleting agent, a prune is, so no verb may assert the row
     * still exists; the honest answer names both facts instead of a TypeError dressed as a 500.
     */
    public static function vanishedAfter(string $verb, string $correlationId): self
    {
        return new self(sprintf(
            'Saga %s vanished between the %s and reading it back; the %s applied, but the instance is gone.',
            $correlationId,
            $verb,
            $verb,
        ));
    }
}
