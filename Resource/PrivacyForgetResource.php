<?php

declare(strict_types=1);

namespace Storm\ApiOps\Resource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use Storm\ApiOps\Error\AnonymousMutationRefused;
use Storm\ApiOps\State\PrivacyForgetProcessor;
use Storm\Ledger\Crypto\ForgetOutcome;

/**
 * The forgetting verb of crypto-shredding over HTTP, the twin of `storm:privacy:forget` through
 * the same one `SubjectForgetter` assembly, so the two channels can never tell different stories:
 *
 * - Destroy the subject's cipher key, the tombstone kept as the proof
 * - Run every volunteering projection's `ForgetsSubject` hook per home in one transaction
 * - Answer the report that names what ran AND what did not
 *
 * Where this surface earns its keep over the console: the actor. An article-17 erasure is a
 * decision someone made on someone's request, and the ops log records WHO beside the optional
 * stated why. Idempotent like the console: re-forgetting answers 200 with `keyDestroyed: false`,
 * the tombstone standing; scriptable, never an error.
 *
 * The honest-scope clauses of the console report, pre-marking cleartext bytes, pre-marking
 * snapshots and the backup-retention window, are structural here: `untouched` lists the read-model
 * projections a rebuild must catch up. The doctrine itself stays in the learn, since an API field
 * cannot carry one.
 *
 * @see \Storm\Ledger\Console\PrivacyForgetCommand the console twin
 * @see \Storm\Ledger\Crypto\SubjectForgetter the one assembly both render
 */
#[ApiResource(
    shortName: 'StormPrivacy',
    operations: [
        new Post(
            uriTemplate: '/_storm/privacy/{subject}/forget',
            uriVariables: ['subject'],
            status: 200,
            input: ForgetSubjectInput::class,
            processor: PrivacyForgetProcessor::class,
        ),
    ],
    // the ops surface is operator tooling, discoverable through describe, never advertised in the
    // app's public API docs: a firewalled /_storm with public docs would still map every cancel,
    // redrive and crypto-shred endpoint for anyone who asks /api/docs
    openapi: false,
    exceptionToStatus: [
        AnonymousMutationRefused::class => 403,
    ],
)]
final readonly class PrivacyForgetResource
{
    /**
     * @param  list<string>  $touched  volunteer projections whose hook ran and committed
     * @param  list<string>  $untouched  registered read-model projections with NO hook; reset +
     *                                   rebuild refolds them onto fallbacks
     */
    public function __construct(
        public string $subject,
        public bool $keyDestroyed,
        public array $touched,
        public array $untouched,
    ) {}

    public static function fromOutcome(string $subject, ForgetOutcome $outcome): self
    {
        return new self($subject, $outcome->keyDestroyed, $outcome->touched, $outcome->untouched);
    }
}
