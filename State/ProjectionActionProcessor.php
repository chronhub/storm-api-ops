<?php

declare(strict_types=1);

namespace Storm\ApiOps\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Doctrine\DBAL\Exception;
use JsonException;
use LogicException;
use Override;
use Storm\ApiOps\Error\AnonymousMutationRefused;
use Storm\ApiOps\Error\ProjectionNotFound;
use Storm\ApiOps\Error\ProjectionTransitionRefused;
use Storm\ApiOps\OpsActorGate;
use Storm\ApiOps\OpsAuditLog;
use Storm\ApiOps\Resource\PauseProjectionInput;
use Storm\ApiOps\Resource\ProjectionResource;
use Storm\Projector\Exception\ProjectionBusy;
use Storm\Projector\Exception\ProjectionNotFailed;
use Storm\Projector\Exception\UnknownProjection;
use Storm\Projector\Exception\UnsupportedProjection;
use Storm\Projector\Management\ProjectionManagement;
use Storm\Projector\Store\ProjectionLifecycleStore;
use Storm\Projector\Store\ProjectionStatus;
use Throwable;

/**
 * The projection status controls over HTTP, the twins of the console marker commands and of
 * `storm:projection:retry` / `storm:projection:reset`, over the SAME single sources: the transition
 * predicates on `ProjectionStatus` for pause/resume/stop, and `ProjectionManagement`'s own guards
 * for retry/reset. Each operation declares its verb in `storm_ops_action`; a successful mutation
 * answers the projection's fresh truth, exactly what the read would serve.
 *
 * Every action lands in the audit log, refused or applied, before the refusal propagates.
 *
 * @implements ProcessorInterface<PauseProjectionInput|mixed, ProjectionResource>
 */
final readonly class ProjectionActionProcessor implements ProcessorInterface
{
    public function __construct(
        private ProjectionLifecycleStore $store,
        private ProjectionManagement $management,
        private ProjectionResourceFactory $factory,
        private OpsAuditLog $audit,
        private OpsActorGate $gate,
    ) {}

    /**
     * {@inheritDoc}
     *
     * @throws AnonymousMutationRefused when no actor is bound and the app did not opt out, a 403
     * @throws ProjectionNotFound when no checkpoint row carries the name, a 404 by declaration
     * @throws ProjectionTransitionRefused when the state machine refuses the verb, a 409
     * @throws UnknownProjection when retry/reset name no registered projection, a 404
     * @throws ProjectionBusy when a worker holds a live lease against retry/reset, a 409
     * @throws ProjectionNotFailed when retry aims at a projection that did not fail, a 409
     * @throws UnsupportedProjection when reset aims at a projection with no output to clear, a 422
     * @throws JsonException when a stored row is malformed
     * @throws Throwable on a storage failure of the mutation or the fresh read
     */
    #[Override]
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ProjectionResource
    {
        // @infection-ignore-all; equivalent: the cast serves the ANALYSER, the array being typed mixed; the router only ever supplies strings, so dropping it changes no runtime value
        $name = (string) ($uriVariables['name'] ?? '');
        // @infection-ignore-all; equivalent: the cast serves the ANALYSER, the array being typed mixed; the router only ever supplies strings, so dropping it changes no runtime value
        $action = (string) ($operation->getExtraProperties()['storm_ops_action'] ?? '');

        // before the row lookup on purpose: an anonymous caller learns nothing, not even existence
        $this->gate->assertOwnedIdentity($action, $name);

        $row = $this->store->findRow($name);
        if ($row === null) {
            $this->audit->record($action, $name, 'refused: no such projection');

            throw ProjectionNotFound::named($name);
        }

        try {
            $this->apply($action, $name, $row->status, $data);
        } catch (Throwable $e) {
            // an infrastructure outage is a FAILURE, never a refusal: the audit line must not
            // read as an operator's verb declined when the store simply went away; every named
            // decline, the module's own, the wiring faults and the projector's, stays a refusal
            $verdict = $e instanceof Exception ? 'failed: ' : 'refused: ';
            $this->audit->record($action, $name, $verdict.$e->getMessage());

            throw $e;
        }

        $this->audit->record($action, $name, 'applied');

        $fresh = $this->store->findRow($name);

        if ($fresh === null) {
            // the verb applied and a retire won the read-back: answering the PRE-mutation row as
            // fresh truth is the lie this surface refuses everywhere else
            throw ProjectionNotFound::named($name);
        }

        return $this->factory->fromRow($fresh);
    }

    /**
     * @throws LogicException when the operation declares an action this switch does not know, a
     *                        wiring fault in the resource declaration, never a caller's 404
     * @throws Throwable propagated from the store / management mutation
     */
    private function apply(string $action, string $name, ProjectionStatus $status, mixed $data): void
    {
        switch ($action) {
            case 'pause':
                // @infection-ignore-all; equivalent: negating a statement-position `expr || throw` discards the negated value, so every path, throw included, is byte-identical
                $status->canPause() || throw ProjectionTransitionRefused::of('pause', $status);
                // @infection-ignore-all; equivalent: same statement-position `expr || throw` shape, the negation has no observable value
                $this->store->pause($name, $data instanceof PauseProjectionInput ? $data->forSeconds : null, ...self::statesWhere(static fn (ProjectionStatus $s): bool => $s->canPause()))
                    || throw ProjectionTransitionRefused::raced('pause', $name);
                break;
            case 'resume':
                $status->canResume() || throw ProjectionTransitionRefused::of('resume', $status);
                $this->store->resume($name, ...self::statesWhere(static fn (ProjectionStatus $s): bool => $s->canResume()))
                    || throw ProjectionTransitionRefused::raced('resume', $name);
                break;
            case 'stop':
                $status->canStop() || throw ProjectionTransitionRefused::of('stop', $status);
                $this->stop($name);
                break;
            case 'retry':
                $this->management->retry($name);
                break;
            case 'reset':
                $this->management->reset($name);
                break;
            default:
                throw new LogicException(sprintf(
                    'Unknown ops action "%s": the operation\'s storm_ops_action extra property names no known verb — a resource declaration fault, not a missing projection.',
                    $action,
                ));
        }
    }

    /**
     * The console stop nuance, kept: with no live worker there is nothing to wind down, so the row
     * goes straight to idle; a live worker sees `stopping` and exits at its next heartbeat. Both the
     * liveness question and the write happen in ONE statement, so a worker failing in between cannot
     * have its `failed` overwritten by this command's stale decision.
     *
     * @throws ProjectionTransitionRefused when the row left the stoppable states mid-request
     * @throws Exception on a DBAL failure of the status mutation
     */
    private function stop(string $name): void
    {
        $landed = $this->store->requestStop($name, ...self::statesWhere(static fn (ProjectionStatus $s): bool => $s->canStop()));

        if ($landed === null) {
            throw ProjectionTransitionRefused::raced('stop', $name);
        }
    }

    /**
     * The statuses a predicate accepts, so the compare-and-set carries the SAME state machine the
     * pre-read validated with: derived, never a second hand-kept list that could drift from it.
     *
     * @param  callable(ProjectionStatus): bool  $predicate
     * @return list<ProjectionStatus>
     */
    private static function statesWhere(callable $predicate): array
    {
        // @infection-ignore-all; equivalent: the reindex serves the declared list type only; the sole call sites spread the result, and a spread is insensitive to keys
        return array_values(array_filter(ProjectionStatus::cases(), $predicate));
    }
}
