<?php

declare(strict_types=1);

namespace Storm\ApiOps\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Override;
use Storm\ApiOps\Error\AnonymousMutationRefused;
use Storm\ApiOps\Error\OperatorPermissionRefused;
use Storm\ApiOps\Error\PrivacyForgetFailed;
use Storm\ApiOps\OpsActorGate;
use Storm\ApiOps\OpsAuditLog;
use Storm\ApiOps\Resource\ForgetSubjectInput;
use Storm\ApiOps\Resource\PrivacyForgetResource;
use Storm\Ledger\Crypto\SubjectForgetter;
use Storm\Ledger\Exception\ForgetIncomplete;
use Storm\Ledger\Exception\PersonalDataNotDeclared;
use Storm\Support\Error\AuditDigest;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Throwable;

use function sprintf;
use function trim;

/**
 * The HTTP half of the forget, delegating to the one assembly the console renders.
 *
 * It re-decides nothing and only adds what this channel owns: the acting identity, refused anonymous,
 * and the audit line an erasure request must leave behind, which reads `applied`, `already` for the
 * idempotent re-run, or `incomplete: …` and `failed: …` before the failure surfaces as the 500 an
 * operator must see.
 *
 * A failure line is the outline of its chain: each level by its class, code and SQLSTATE, so the trail
 * of an erasure never quotes what a driver message holds of the subject. Only the messages Storm writes
 * stay verbatim, since neither quotes a value of the subject's data:
 *
 * - `ForgetIncomplete`, the recovery instruction, which names the subject and the failing projection
 * - `PersonalDataNotDeclared`, the wiring fault, which names no value
 *
 * The 500 carries that same line and never the cause, since the error listener logs the exception it
 * receives and a log formatter writes the message of every level of its chain.
 *
 * @implements ProcessorInterface<ForgetSubjectInput, PrivacyForgetResource>
 */
final readonly class PrivacyForgetProcessor implements ProcessorInterface
{
    public function __construct(
        private SubjectForgetter $forgetter,
        private OpsAuditLog $audit,
        private OpsActorGate $gate,
    ) {}

    /**
     * {@inheritDoc}
     *
     * @throws OperatorPermissionRefused when the application does not grant operator access
     * @throws Throwable when an application identity or permission backend cannot answer
     * @throws AnonymousMutationRefused when no actor is bound and the app did not opt out, a 403
     * @throws UnprocessableEntityHttpException when the subject path segment is blank, a 422
     * @throws PrivacyForgetFailed when the forget failed, or a volunteer's hook stopped it after the key
     *                             was destroyed and a re-run completes it; a 500 an operator must see
     */
    #[Override]
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PrivacyForgetResource
    {
        $subject = trim(OpsRoute::string($uriVariables, 'subject'));
        $input = $data; // the operation declares the input class; API Platform denormalized it

        // before anything: an anonymous caller learns nothing, not even key state
        $this->gate->assertOwnedIdentity('privacy_forget', $subject);

        if ($subject === '') {
            throw new UnprocessableEntityHttpException('The subject id must be a non-empty string — the value of the #[Personal] subject payload key.');
        }

        try {
            $outcome = $this->forgetter->forget($subject);
        } catch (ForgetIncomplete $e) {
            // the audit must say the decision was taken even though its read-model half stalled;
            // the same outline then surfaces as the 500 the operator has to see
            $failure = 'incomplete: '.self::outline($e);
            $this->audit->record('privacy_forget', $subject, $failure);

            throw PrivacyForgetFailed::because($failure);
        } catch (Throwable $e) {
            // the most irreversible verb of the surface: an outage mid-destruction must leave a
            // line, never a silent gap exactly where the trail matters most
            $failure = 'failed: '.self::outline($e);
            $this->audit->record('privacy_forget', $subject, $failure);

            throw PrivacyForgetFailed::because($failure);
        }

        $why = trim((string) ($input->reason ?? ''));

        $this->audit->record('privacy_forget', $subject, sprintf(
            '%s%s',
            $outcome->keyDestroyed ? 'applied' : 'already',
            $why === '' ? '' : ' — '.$why,
        ));

        return PrivacyForgetResource::fromOutcome($subject, $outcome);
    }

    private static function outline(Throwable $failure): string
    {
        return AuditDigest::outline(
            $failure,
            static fn (Throwable $level): bool => $level instanceof ForgetIncomplete || $level instanceof PersonalDataNotDeclared,
        );
    }
}
