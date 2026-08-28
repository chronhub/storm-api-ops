<?php

declare(strict_types=1);

namespace Storm\ApiOps\Resource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use Storm\ApiOps\Error\AnonymousMutationRefused;
use Storm\ApiOps\Error\AnonymousReadRefused;
use Storm\ApiOps\Error\SagaPauseRefused;
use Storm\ApiOps\Error\UnknownWorkflowType;
use Storm\ApiOps\OpsActorGate;
use Storm\ApiOps\State\SagaTypePauseProcessor;

/**
 * A workflow TYPE as an ops surface: the fleet-wide freeze, the HTTP twin of `storm:saga:pause`
 * with no correlation. Registering the type in `workflow_pauses` gates execution AND births, the
 * stop-the-bleeding move for a broken handler or a downstream maintenance window; the resume
 * deletes the registration, overdue timers firing at the next cycle at their original instants.
 * The pause is idempotent, the console contract: re-freezing an already frozen type keeps the
 * first stamp and reason; resuming an unfrozen one is the 409 that says so. A type no registry
 * knows is a 404 on the freeze, since the row it would write gates nothing.
 *
 * Authorization is the same ops-zone access_control as every `/_storm` surface, with
 * {@see OpsActorGate} refusing anonymous mutations beneath it.
 */
#[ApiResource(
    shortName: 'StormSagaType',
    operations: [
        new Post(
            uriTemplate: '/_storm/saga-types/{workflowType}/pause',
            uriVariables: ['workflowType'],
            status: 200,
            input: PauseSagaTypeInput::class,
            processor: SagaTypePauseProcessor::class,
            extraProperties: ['storm_ops_action' => 'pause'],
        ),
        new Post(
            uriTemplate: '/_storm/saga-types/{workflowType}/resume',
            uriVariables: ['workflowType'],
            status: 200,
            input: false,
            processor: SagaTypePauseProcessor::class,
            extraProperties: ['storm_ops_action' => 'resume'],
        ),
    ],
    // the ops surface is operator tooling, discoverable through describe, never advertised in the
    // app's public API docs: a firewalled /_storm with public docs would still map every cancel,
    // redrive and crypto-shred endpoint for anyone who asks /api/docs
    openapi: false,
    exceptionToStatus: [
        AnonymousMutationRefused::class => 403,
        AnonymousReadRefused::class => 403,
        UnknownWorkflowType::class => 404,
        SagaPauseRefused::class => 409,
    ],
)]
final readonly class SagaTypeResource
{
    public function __construct(
        public string $workflowType,
        /** Whether the type is registered in `workflow_pauses` AFTER the verb ran. */
        public bool $paused,
    ) {}
}
