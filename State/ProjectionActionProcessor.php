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
use Storm\ApiOps\Error\OperatorPermissionRefused;
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
use Storm\Support\Error\AuditDigest;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use UnhandledMatchError;

/**
 * The projection status controls over HTTP, the twins of the console marker commands and of
 * `storm:projection:retry` and `storm:projection:reset`, over the same single sources.
 *
 * The transition predicates on `ProjectionStatus` serve pause, resume and stop, and
 * `ProjectionManagement`'s own guards serve retry and reset. Each operation declares its verb in
 * `storm_ops_action`; a successful mutation answers the projection's fresh truth. Reset without a
 * checkpoint initializes and clears output, then answers 204 without inventing a stored projection
 * state.
 *
 * Every action lands in the audit log as refused, failed or applied before its outcome propagates,
 * except a fault of the resource declaration and a `LogicException` raised before any write: neither
 * mutates anything, and neither leaves a line.
 *
 * @implements ProcessorInterface<PauseProjectionInput|mixed, ProjectionResource|Response>
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
     * @throws OperatorPermissionRefused when the application does not grant operator access
     * @throws Throwable when an application identity or permission backend cannot answer
     * @throws AnonymousMutationRefused when no actor is bound and the app did not opt out, a 403
     * @throws ProjectionNotFound when a non-reset action has no checkpoint, or its fresh row disappears
     * @throws ProjectionTransitionRefused when the state machine refuses the verb, a 409
     * @throws UnknownProjection when retry/reset name no registered projection, a 404
     * @throws ProjectionBusy when a worker holds a live lease against retry/reset, a 409
     * @throws ProjectionNotFailed when retry aims at a projection that did not fail, a 409
     * @throws UnsupportedProjection when reset aims at a projection with no output to clear, a 422
     * @throws LogicException when the operation declares an action this processor does not know, a
     *                        wiring fault in the resource declaration, or when a store guard refuses
     *                        before any write, such as an empty compare-and-set
     * @throws JsonException when a stored row is malformed
     * @throws Throwable on a storage failure of the mutation or the fresh read
     */
    #[Override]
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ProjectionResource|Response
    {
        $name = OpsRoute::string($uriVariables, 'name');
        $action = OpsRoute::string($operation->getExtraProperties(), 'storm_ops_action');

        // before the row lookup: an anonymous caller learns nothing, not even existence
        $this->gate->assertOwnedIdentity($action, $name);

        // the verb is judged before the row lookup too, as the saga twins judge theirs before any read:
        // a verb no operation declares is a fault of the resource declaration, never a caller's 404
        match ($action) {
            'pause', 'resume', 'stop', 'retry', 'reset' => null,
            default => throw new LogicException(sprintf(
                'Unknown ops action "%s": the operation\'s storm_ops_action extra property names no known verb — a resource declaration fault, not a missing projection.',
                $action,
            )),
        };

        $row = $this->store->findRow($name);
        if ($row === null && $action !== 'reset') {
            $this->audit->record($action, $name, 'refused: no such projection');

            throw ProjectionNotFound::named($name);
        }

        try {
            if ($action === 'reset') {
                $this->management->reset($name);
            } elseif ($row !== null) {
                $this->apply($action, $name, $row->status, $data);
            }
        } catch (ProjectionTransitionRefused|UnknownProjection|ProjectionBusy|ProjectionNotFailed|UnsupportedProjection $e) {
            // the named declines the resource answers with a 4xx, the module's own and the projector's;
            // caught ahead of the wiring arm, since `UnsupportedProjection` is a `LogicException`
            $this->audit->record($action, $name, 'refused: '.$e->getMessage());

            throw $e;
        } catch (LogicException $e) {
            // a code fault raised before any write, such as the store's guard against an empty
            // compare-and-set: nothing mutated, and it leaves no line
            throw $e;
        } catch (Throwable $e) {
            // an outage or an output that could not be cleared is a failure, never a refusal: the
            // audit line must not read as an operator's verb declined
            $this->audit->record($action, $name, 'failed: '.AuditDigest::digest($e));

            throw $e;
        }

        $this->audit->record($action, $name, 'applied');

        $fresh = $this->store->findRow($name);

        if ($fresh === null && $row === null) {
            return new Response(status: Response::HTTP_NO_CONTENT);
        }

        if ($fresh === null) {
            // the verb applied and a retire won the read-back: answering the pre-mutation row as fresh
            // truth would be the false answer this surface refuses everywhere else
            throw ProjectionNotFound::named($name);
        }

        return $this->factory->fromRow($fresh);
    }

    /**
     * Runs one of the four verbs `process()` has already judged; reset never reaches here.
     *
     * The match has no default arm: a verb the judge admits and this match forgets throws, and
     * `process()` audits the throw as failed, where a silent fall-through would answer applied over an
     * unmutated row.
     *
     * @throws UnhandledMatchError when `process()` admits a verb this match has no arm for
     * @throws Throwable propagated from the store or management mutation
     */
    private function apply(string $action, string $name, ProjectionStatus $status, mixed $data): void
    {
        match ($action) {
            'pause' => $this->pause($name, $status, $data instanceof PauseProjectionInput ? $data->forSeconds : null),
            'resume' => $this->resume($name, $status),
            'stop' => $this->stop($name, $status),
            'retry' => $this->management->retry($name),
        };
    }

    /**
     * @throws ProjectionTransitionRefused when the status cannot pause, or the row left the pausable
     *                                     states mid-request
     * @throws Exception on a DBAL failure of the status mutation
     */
    private function pause(string $name, ProjectionStatus $status, ?int $forSeconds): void
    {
        // equivalent: negating a statement-position `expr || throw` discards the negated value, so
        // every path, throw included, is byte-identical
        // @infection-ignore-all
        $status->canPause() || throw ProjectionTransitionRefused::of('pause', $status);
        // equivalent: same statement-position `expr || throw` shape, the negation has
        // no observable value
        // @infection-ignore-all
        $this->store->pause($name, $forSeconds, ...self::statesWhere(static fn (ProjectionStatus $s): bool => $s->canPause()))
            || throw ProjectionTransitionRefused::raced('pause', $name);
    }

    /**
     * @throws ProjectionTransitionRefused when the status cannot resume, or the row left the resumable
     *                                     states mid-request
     * @throws Exception on a DBAL failure of the status mutation
     */
    private function resume(string $name, ProjectionStatus $status): void
    {
        // equivalent: as in pause(), negating a statement-position `expr || throw` discards the
        // negated value, so every path, throw included, is byte-identical
        // @infection-ignore-all
        $status->canResume() || throw ProjectionTransitionRefused::of('resume', $status);
        // equivalent: same statement-position `expr || throw` shape, the negation has
        // no observable value
        // @infection-ignore-all
        $this->store->resume($name, ...self::statesWhere(static fn (ProjectionStatus $s): bool => $s->canResume()))
            || throw ProjectionTransitionRefused::raced('resume', $name);
    }

    /**
     * The console stop nuance, kept: with no live worker there is nothing to wind down, so the row
     * goes straight to idle; a live worker sees `stopping` and exits at its next heartbeat. Both the
     * liveness question and the write happen in one statement, so a worker failing in between cannot
     * have its `failed` overwritten by this command's stale decision.
     *
     * @throws ProjectionTransitionRefused when the status cannot stop, or the row left the stoppable
     *                                     states mid-request
     * @throws Exception on a DBAL failure of the status mutation
     */
    private function stop(string $name, ProjectionStatus $status): void
    {
        // equivalent: as in pause(), negating a statement-position `expr || throw` discards the
        // negated value, so every path, throw included, is byte-identical
        // @infection-ignore-all
        $status->canStop() || throw ProjectionTransitionRefused::of('stop', $status);
        $landed = $this->store->requestStop($name, ...self::statesWhere(static fn (ProjectionStatus $s): bool => $s->canStop()));

        if ($landed === null) {
            throw ProjectionTransitionRefused::raced('stop', $name);
        }
    }

    /**
     * The statuses a predicate accepts, so the compare-and-set carries the same state machine the
     * pre-read validated with: derived, never a second hand-kept list that could drift from it.
     *
     * @param  callable(ProjectionStatus): bool  $predicate
     * @return list<ProjectionStatus>
     */
    private static function statesWhere(callable $predicate): array
    {
        // equivalent: the reindex serves the declared list type only; the sole call sites spread the result,
        // and a spread is insensitive to keys
        // @infection-ignore-all
        return array_filter(ProjectionStatus::cases(), $predicate)
            |> array_values(...);
    }
}
