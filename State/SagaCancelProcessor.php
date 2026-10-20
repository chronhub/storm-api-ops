<?php

declare(strict_types=1);

namespace Storm\ApiOps\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use LogicException;
use Override;
use Storm\ApiOps\Error\AnonymousMutationRefused;
use Storm\ApiOps\Error\OperatorPermissionRefused;
use Storm\ApiOps\Error\SagaCancelRefused;
use Storm\ApiOps\Error\SagaCommandNotFound;
use Storm\ApiOps\OpsActorGate;
use Storm\ApiOps\OpsAuditLog;
use Storm\ApiOps\Resource\CancelSagaInput;
use Storm\ApiOps\Resource\SagaResource;
use Storm\Saga\Engine\SagaOperator;
use Storm\Saga\Exception\SagaStorageFailure;
use Storm\Saga\Exception\StaleWorkflowInstance;
use Storm\Saga\Exception\WorkflowNotFound;
use Storm\Saga\Store\Inspection\SagaInspectionGateway;
use Storm\Saga\Store\WorkflowStatus;
use Storm\Support\Error\AuditDigest;
use Throwable;

/**
 * The operator's cancel over HTTP, the twin of `storm:saga:cancel`, through the same engine verb.
 *
 * It halts the instance where it sits, compensates what is safe to undo by positional eligibility, and
 * refuses at an effect-gating wait unless `force` owns the risk. The operator reason travels into the
 * event store on `SagaCancelled`, the durable audit trail; the ops log records the HTTP actor beside
 * it.
 *
 * A successful cancel answers the instance's fresh snapshot: status, and the compensation log the
 * cancel just wrote, the first thing an operator reads back.
 *
 * A refusal answers the cause a fresh read of the instance establishes, since the engine collapses
 * every refusal to one `false`: no instance, already settled, or still running, where only an unforced
 * cancel is pointed at `force`. The ops log records the same sentence the caller receives. When that
 * read itself fails, the ops log records a failure and the read's error escapes.
 *
 * @implements ProcessorInterface<CancelSagaInput|mixed, SagaResource>
 */
final readonly class SagaCancelProcessor implements ProcessorInterface
{
    public function __construct(
        private SagaOperator $engine,
        private SagaInspectionGateway $gateway,
        private OpsAuditLog $audit,
        private OpsActorGate $gate,
    ) {}

    /**
     * {@inheritDoc}
     *
     * @throws OperatorPermissionRefused when the application does not grant operator access
     * @throws Throwable when an application identity or permission backend cannot answer
     * @throws AnonymousMutationRefused when no actor is bound and the app did not opt out, a 403
     * @throws WorkflowNotFound when the workflow type is not registered, a 404 by declaration
     * @throws SagaCancelRefused when the engine declines the cancel, a 409 naming the cause a fresh read
     *                           establishes
     * @throws StaleWorkflowInstance when the cancel loses to a competing step and may be retried, a 409
     * @throws SagaStorageFailure when the saga storage fails
     * @throws Throwable from the transaction fence or a post-commit listener
     */
    #[Override]
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): SagaResource
    {
        if (! $data instanceof CancelSagaInput) {
            throw new LogicException('The cancel operation declares its input class; anything else is a resource declaration fault.');
        }

        $correlationId = OpsRoute::string($uriVariables, 'correlationId');
        $input = $data;
        $subject = $input->workflowType.'/'.$correlationId;

        // before the engine: an anonymous caller learns nothing, not even existence
        $this->gate->assertOwnedIdentity('cancel', $subject);

        try {
            if (! $this->engine->cancel($input->workflowType, $correlationId, $input->reason, $input->force)) {
                throw $this->refusal($input->workflowType, $correlationId, $input->force);
            }
        } catch (WorkflowNotFound|StaleWorkflowInstance|SagaCancelRefused $e) {
            // the engine's named declines and the cause a fresh read gives a falsy cancel, answered
            // 404 and 409 by the resource
            $this->audit->record('cancel', $subject, 'refused: '.$e->getMessage());

            throw $e;
        } catch (Throwable $e) {
            // a storage outage, a compensation that could not complete, or a read that failed while
            // naming a refusal is a failure, never a refusal: the audit line must not read as an
            // operator's verb declined
            $this->audit->record('cancel', $subject, 'failed: '.AuditDigest::digest($e));

            throw $e;
        }

        $this->audit->record('cancel', $subject, 'applied');

        $snapshots = $this->gateway->inspect($correlationId, $input->workflowType);

        // cancel halts, never deletes; a retention prune deletes, and it can win the read-back,
        // the same race the redrive twin names rather than letting a TypeError dress up as a 500
        return $snapshots === []
            ? throw SagaCommandNotFound::vanishedAfter('cancel', $correlationId)
            : SagaResource::fromSnapshot($snapshots[0]);
    }

    private function refusal(string $type, string $correlationId, bool $force): SagaCancelRefused
    {
        // the engine collapses every refusal to one false, so the cause is established here from a
        // read taken after the refusal: the answer describes the instance as the operator's next
        // attempt will find it, the actionable truth even when the row moved in between
        $snapshots = $this->gateway->inspect($correlationId, $type);

        if ($snapshots === []) {
            return SagaCancelRefused::noInstance($type, $correlationId);
        }

        if ($snapshots[0]->status !== WorkflowStatus::Running->value) {
            return SagaCancelRefused::alreadySettled($type, $correlationId, $snapshots[0]->status);
        }

        // still running: unforced, an effect-gating wait and a competing step both decline and the
        // read cannot tell them apart; forced, the gating wait no longer refuses, so only a
        // competing step can have held it, and force must never be advised again
        return $force
            ? SagaCancelRefused::atCompetingStepDespiteForce($type, $correlationId)
            : SagaCancelRefused::atGatingWaitOrCompetingStep($type, $correlationId);
    }
}
