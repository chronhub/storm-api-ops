<?php

declare(strict_types=1);

namespace Storm\ApiOps\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use LogicException;
use Override;
use Psr\EventDispatcher\EventDispatcherInterface;
use Storm\ApiOps\Error\AnonymousMutationRefused;
use Storm\ApiOps\Error\SagaCommandNotFound;
use Storm\ApiOps\Error\SagaPauseRefused;
use Storm\ApiOps\OpsActorGate;
use Storm\ApiOps\OpsAuditLog;
use Storm\ApiOps\Resource\PauseSagaInput;
use Storm\ApiOps\Resource\ResumeSagaInput;
use Storm\ApiOps\Resource\SagaResource;
use Storm\Saga\Event\SagaPaused;
use Storm\Saga\Event\SagaResumed;
use Storm\Saga\Exception\SagaStorageFailure;
use Storm\Saga\Store\Inspection\SagaInspectionGateway;
use Storm\Saga\Store\WorkflowId;
use Storm\Saga\Store\WorkflowInstances;
use Storm\Saga\Store\WorkflowPauses;
use Throwable;

/**
 * The operator freeze on ONE instance over HTTP, the twins of `storm:saga:pause` / `storm:saga:resume`
 * with a correlation, through the same store verbs: `paused_at` and its reason stamped on a RUNNING
 * row, or lifted with the due timers simply claimable again at their original instants. The status
 * stays untouched; a paused saga is a living one that is not executed. Each operation declares its
 * verb in `storm_ops_action`; the announcements the console dispatches ride here too, so telemetry
 * cannot tell the two surfaces apart. A refusal is the console's warning as a 409: the verb reports
 * instead of guessing.
 *
 * A successful verb answers the instance's fresh snapshot, `paused_at` and `paused_reason` visible;
 * a frozen saga must SAY so wherever it is read.
 *
 * @implements ProcessorInterface<PauseSagaInput|ResumeSagaInput|mixed, SagaResource>
 */
final readonly class SagaPauseProcessor implements ProcessorInterface
{
    public function __construct(
        private WorkflowPauses $pauses,
        private WorkflowInstances $instances,
        private SagaInspectionGateway $gateway,
        private OpsAuditLog $audit,
        private OpsActorGate $gate,
        private EventDispatcherInterface $events,
    ) {}

    /**
     * {@inheritDoc}
     *
     * @throws AnonymousMutationRefused when no actor is bound and the app did not opt out, a 403
     * @throws SagaPauseRefused when there is nothing to freeze or nothing to lift, a 409
     * @throws LogicException when the operation declares an input class or an action this processor
     *                        does not know, a wiring fault in the resource declaration, never a
     *                        caller's 404
     * @throws SagaStorageFailure when the saga storage fails
     * @throws Throwable from the store or a post-dispatch listener
     */
    #[Override]
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): SagaResource
    {
        if (! $data instanceof PauseSagaInput && ! $data instanceof ResumeSagaInput) {
            throw new LogicException('The freeze operations declare their input class; anything else is a resource declaration fault.');
        }

        // @infection-ignore-all; equivalent: the cast serves the ANALYSER, the array being typed mixed; the router only ever supplies strings, so dropping it changes no runtime value
        $correlationId = (string) ($uriVariables['correlationId'] ?? '');
        // @infection-ignore-all; equivalent: the cast serves the ANALYSER, the array being typed mixed; the router only ever supplies strings, so dropping it changes no runtime value
        $action = (string) ($operation->getExtraProperties()['storm_ops_action'] ?? '');
        $type = $data->workflowType;
        $subject = $type.'/'.$correlationId;

        // before the store on purpose: an anonymous caller learns nothing, not even existence
        $this->gate->assertOwnedIdentity($action, $subject);

        $id = new WorkflowId($type, $correlationId);

        try {
            switch ($action) {
                case 'pause':
                    $reason = $data instanceof PauseSagaInput ? $data->reason : null;
                    if (! $this->pauses->pauseInstance($id, $reason)) {
                        $this->audit->record($action, $subject, 'refused: nothing to freeze');

                        throw SagaPauseRefused::nothingToFreeze($type, $correlationId);
                    }
                    $row = $this->instances->find($id);
                    $this->events->dispatch(new SagaPaused($type, $correlationId, $row->generation ?? 0, $reason));
                    break;
                case 'resume':
                    if (! $this->pauses->resumeInstance($id)) {
                        $this->audit->record($action, $subject, 'refused: nothing to lift');

                        throw SagaPauseRefused::nothingToLift($type, $correlationId);
                    }
                    $row = $this->instances->find($id);
                    $this->events->dispatch(new SagaResumed($type, $correlationId, $row->generation ?? 0));
                    break;
                default:
                    throw new LogicException(sprintf(
                        'Unknown ops action "%s": the operation\'s storm_ops_action extra property names no known verb — a resource declaration fault, not a missing saga.',
                        $action,
                    ));
            }
        } catch (SagaPauseRefused|LogicException $e) {
            throw $e; // the refusal recorded at its branch; the wiring fault mutated nothing and leaves no line
        } catch (Throwable $e) {
            // an infrastructure outage is a FAILURE, never a refusal: the audit line must not read
            // as an operator's verb declined when the store simply went away
            $this->audit->record($action, $subject, 'failed: '.$e->getMessage());

            throw $e;
        }

        $this->audit->record($action, $subject, 'applied');

        $snapshots = $this->gateway->inspect($correlationId, $type);

        // pause and resume never delete; a retention PRUNE deletes, and it can win the read-back,
        // the same race this class already defends at the generation read a few lines above
        return $snapshots === []
            ? throw SagaCommandNotFound::vanishedAfter($action, $correlationId)
            : SagaResource::fromSnapshot($snapshots[0]);
    }
}
