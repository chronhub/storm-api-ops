<?php

declare(strict_types=1);

namespace Storm\ApiOps\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use LogicException;
use Override;
use Storm\ApiOps\Error\AnonymousMutationRefused;
use Storm\ApiOps\Error\SagaPauseRefused;
use Storm\ApiOps\Error\UnknownWorkflowType;
use Storm\ApiOps\OpsActorGate;
use Storm\ApiOps\OpsAuditLog;
use Storm\ApiOps\Resource\PauseSagaTypeInput;
use Storm\ApiOps\Resource\SagaTypeResource;
use Storm\Saga\Build\WorkflowRegistry;
use Storm\Saga\Exception\SagaStorageFailure;
use Storm\Saga\Store\WorkflowPauses;
use Throwable;

/**
 * The fleet-wide freeze over HTTP, the twins of `storm:saga:pause` / `storm:saga:resume` with the
 * type alone: one row in `workflow_pauses` gates every step AND every birth of the type; deleting
 * it restores the regime. The pause is idempotent by the store's own contract, the first stamp and
 * reason standing; the resume of an unfrozen type is the console's warning as a 409. The answered
 * resource states the registry's truth AFTER the verb, read back rather than assumed.
 *
 * The pause is registry-checked, its console twin's refusal as a 404: the store stamps any string
 * handed to it, and the resume of an unknown type already answers loud on its own.
 *
 * @implements ProcessorInterface<PauseSagaTypeInput|mixed, SagaTypeResource>
 */
final readonly class SagaTypePauseProcessor implements ProcessorInterface
{
    public function __construct(
        private WorkflowPauses $pauses,
        private OpsAuditLog $audit,
        private OpsActorGate $gate,
        private WorkflowRegistry $registry,
    ) {}

    /**
     * {@inheritDoc}
     *
     * @throws AnonymousMutationRefused when no actor is bound and the app did not opt out, a 403
     * @throws UnknownWorkflowType when freezing a type no registry knows, a 404
     * @throws SagaPauseRefused when resuming a type that was not frozen, a 409
     * @throws LogicException when the operation declares an action this processor does not know, a
     *                        wiring fault in the resource declaration, never a caller's 404
     * @throws SagaStorageFailure when the saga storage fails
     * @throws Throwable from the store
     */
    #[Override]
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): SagaTypeResource
    {
        // @infection-ignore-all; equivalent: the cast serves the ANALYSER, the array being typed mixed; the router only ever supplies strings, so dropping it changes no runtime value
        $type = (string) ($uriVariables['workflowType'] ?? '');
        // @infection-ignore-all; equivalent: the cast serves the ANALYSER, the array being typed mixed; the router only ever supplies strings, so dropping it changes no runtime value
        $action = (string) ($operation->getExtraProperties()['storm_ops_action'] ?? '');

        // before the registry on purpose: an anonymous caller learns nothing, not even existence
        $this->gate->assertOwnedIdentity($action, $type);

        try {
            switch ($action) {
                case 'pause':
                    // the store stamps whatever string it is handed, so an unchecked type installs a row
                    // that gates nothing, answers 200 with `paused: true`, and leaves the fleet running
                    if (! $this->registry->has($type)) {
                        $this->audit->record($action, $type, 'refused: unknown workflow type');

                        throw UnknownWorkflowType::named($type);
                    }

                    $this->pauses->pauseType($type, $data instanceof PauseSagaTypeInput ? $data->reason : null);
                    break;
                case 'resume':
                    if (! $this->pauses->resumeType($type)) {
                        $this->audit->record($action, $type, 'refused: type not frozen');

                        throw SagaPauseRefused::typeNotFrozen($type);
                    }
                    break;
                default:
                    throw new LogicException(sprintf(
                        'Unknown ops action "%s": the operation\'s storm_ops_action extra property names no known verb — a resource declaration fault, not a missing type.',
                        $action,
                    ));
            }
        } catch (UnknownWorkflowType|SagaPauseRefused|LogicException $e) {
            throw $e; // the refusals recorded at their branches; the wiring fault mutated nothing and leaves no line
        } catch (Throwable $e) {
            // an infrastructure outage is a FAILURE, never a refusal: the audit line must not read
            // as an operator's verb declined when the store simply went away
            $this->audit->record($action, $type, 'failed: '.$e->getMessage());

            throw $e;
        }

        $this->audit->record($action, $type, 'applied');

        return new SagaTypeResource($type, $this->pauses->pausedType($type));
    }
}
