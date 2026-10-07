<?php

declare(strict_types=1);

namespace Storm\ApiOps;

use Storm\ApiOps\Error\AnonymousMutationRefused;
use Storm\ApiOps\Error\AnonymousReadRefused;
use Storm\ApiOps\Error\OperatorPermissionRefused;
use Storm\Bureau\IdentityProvider;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Throwable;

/**
 * Require an owned identity and an explicit application permission before accessing ops data.
 *
 * A missing policy denies access. Identity and permission backend failures propagate; they
 * never become an anonymous request or a grant. The firewall remains an additional perimeter.
 * `describe` serves compiled wiring without this gate and needs app-side protection.
 *
 * The separate anonymous read and mutation opt-ins bypass both checks for dev environments.
 * Each bypass is recorded through the best-effort audit channel, like an explicit refusal.
 */
final readonly class OpsActorGate
{
    public function __construct(
        private OpsAuditLog $audit,
        private ?IdentityProvider $identity = null,
        #[Autowire('%storm_api_ops.allow_anonymous_mutations%')]
        private bool $allowAnonymous = false,
        #[Autowire('%storm_api_ops.allow_anonymous_reads%')]
        private bool $allowAnonymousReads = false,
        private ?OpsAuthorization $authorization = null,
    ) {}

    /**
     * @throws AnonymousMutationRefused when no actor is bound and mutation opt-in is disabled
     * @throws OperatorPermissionRefused when no policy grants this mutation
     * @throws Throwable when an application identity or permission backend cannot answer
     */
    public function assertOwnedIdentity(string $action, string $subject): void
    {
        if ($this->allowAnonymous) {
            $this->audit->record($action, $subject, 'bypassed: anonymous mutation opt-in');

            return;
        }

        $actor = $this->identity?->currentActor();
        if ($actor === null) {
            $this->audit->record($action, $subject, 'refused: anonymous mutation');

            throw AnonymousMutationRefused::for($action, $subject);
        }

        if ($this->authorization?->canMutate($actor, $action, $subject) !== true) {
            $this->audit->record($action, $subject, 'refused: operator mutation permission');

            throw OperatorPermissionRefused::for($action, $subject);
        }
    }

    /**
     * @throws AnonymousReadRefused when no actor is bound and read opt-in is disabled
     * @throws OperatorPermissionRefused when no policy grants this read
     * @throws Throwable when an application identity or permission backend cannot answer
     */
    public function assertOwnedIdentityForRead(string $action, string $subject): void
    {
        if ($this->allowAnonymousReads) {
            $this->audit->record($action, $subject, 'bypassed: anonymous read opt-in');

            return;
        }

        $actor = $this->identity?->currentActor();
        if ($actor === null) {
            $this->audit->record($action, $subject, 'refused: anonymous read');

            throw AnonymousReadRefused::for($action, $subject);
        }

        if ($this->authorization?->canRead($actor, $action, $subject) !== true) {
            $this->audit->record($action, $subject, 'refused: operator read permission');

            throw OperatorPermissionRefused::for($action, $subject);
        }
    }
}
