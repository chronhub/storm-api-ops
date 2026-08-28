<?php

declare(strict_types=1);

namespace Storm\ApiOps\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use LogicException;
use Override;
use Storm\ApiOps\Error\AnonymousMutationRefused;
use Storm\ApiOps\Error\SagaCommandNotFound;
use Storm\ApiOps\Error\SagaRedriveRefused;
use Storm\ApiOps\OpsActorGate;
use Storm\ApiOps\OpsAuditLog;
use Storm\ApiOps\Resource\RedriveSagaInput;
use Storm\ApiOps\Resource\SagaResource;
use Storm\Saga\Exception\SagaStorageFailure;
use Storm\Saga\Outbox\FailedWorkflowCommands;
use Storm\Saga\Outbox\RedriveOutcome;
use Storm\Saga\Store\Inspection\SagaInspectionGateway;
use Throwable;

use function sprintf;

/**
 * The operator's repair over HTTP, the twin of `storm:saga:redrive`, through the same store verb:
 * send a dead-lettered command back into flight instead of cancelling the saga around it.
 *
 * It re-decides NOTHING. All four guards live in the store's UPDATE predicate, and the sealed
 * {@see \Storm\Saga\Outbox\RedriveOutcome} it returns is what this translates into HTTP: a 404 for
 * a command that does not exist, a 409 naming the guard for everything else. Two channels, one
 * decision.
 *
 * Where this surface earns its keep over the console: the actor. A forced redrive accepts that the
 * effect may execute twice, and the ops log records WHO decided it beside the reason they gave. The
 * console has the verb; only this one has the name.
 *
 * A successful redrive answers the instance's fresh snapshot, whose outbox row now reads `pending`
 * again, the first thing an operator checks.
 *
 * @implements ProcessorInterface<RedriveSagaInput|mixed, SagaResource>
 */
final readonly class SagaRedriveProcessor implements ProcessorInterface
{
    public function __construct(
        private FailedWorkflowCommands $outbox,
        private SagaInspectionGateway $gateway,
        private OpsAuditLog $audit,
        private OpsActorGate $gate,
    ) {}

    /**
     * {@inheritDoc}
     *
     * @throws AnonymousMutationRefused when no actor is bound and the app did not opt out, a 403
     * @throws SagaCommandNotFound when no such command exists, or the instance vanished, a 404 by declaration
     * @throws SagaRedriveRefused when a guard declined, a 409 naming which one
     * @throws SagaStorageFailure when the saga storage fails
     */
    #[Override]
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): SagaResource
    {
        if (! $data instanceof RedriveSagaInput) {
            throw new LogicException('The redrive operation declares its input class; anything else is a resource declaration fault.');
        }

        // @infection-ignore-all; equivalent: the cast serves the ANALYSER, the array being typed mixed; the router only ever supplies strings, so dropping it changes no runtime value
        $correlationId = (string) ($uriVariables['correlationId'] ?? '');
        $input = $data;
        $subject = $correlationId.'/'.$input->messageId;

        // before the store on purpose: an anonymous caller learns nothing, not even existence
        $this->gate->assertOwnedIdentity('redrive', $subject);

        try {
            $outcome = $this->outbox->redrive($correlationId, $input->messageId, $input->force);
        } catch (Throwable $e) {
            // an infrastructure outage is a FAILURE, never a refusal: the audit line must not read
            // as a guard's decline when the outbox simply went away
            $this->audit->record('redrive', $subject, 'failed: '.$e->getMessage());

            throw $e;
        }

        if ($outcome !== RedriveOutcome::Redriven) {
            $this->audit->record('redrive', $subject, 'refused: '.$outcome->value);

            // a command that does not exist is not a conflict, it is a wrong address
            throw $outcome === RedriveOutcome::NotFound
                ? SagaCommandNotFound::command($correlationId, $input->messageId)
                : SagaRedriveRefused::because($outcome, $correlationId, $input->messageId);
        }

        $this->audit->record('redrive', $subject, $input->force
            ? sprintf('applied: forced — %s', (string) $input->reason)
            : 'applied');

        $snapshots = $this->gateway->inspect($correlationId, null);

        // the guards proved the saga was running when the row flipped; a settle since then would still
        // leave the row readable, so an empty answer here can only mean a prune raced us
        return $snapshots === []
            ? throw SagaCommandNotFound::instance($correlationId)
            : SagaResource::fromSnapshot($snapshots[0]);
    }
}
