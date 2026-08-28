<?php

declare(strict_types=1);

namespace Storm\ApiOps\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Override;
use Storm\ApiOps\Error\AnonymousMutationRefused;
use Storm\ApiOps\OpsActorGate;
use Storm\ApiOps\OpsAuditLog;
use Storm\ApiOps\Resource\ForgetSubjectInput;
use Storm\ApiOps\Resource\PrivacyForgetResource;
use Storm\Ledger\Crypto\SubjectForgetter;
use Storm\Ledger\Exception\ForgetIncomplete;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Throwable;

use function sprintf;
use function trim;

/**
 * The HTTP half of the forget, delegating to the ONE assembly the console renders. It re-decides
 * nothing, it only adds what this channel owns: the acting identity, refused anonymous, and the
 * audit line an erasure request must leave behind, which reads `applied`, `already` for the
 * idempotent re-run, or `incomplete: …` before a failed hook's error surfaces as the 500 an
 * operator must see.
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
     * @throws AnonymousMutationRefused when no actor is bound and the app did not opt out, a 403
     * @throws UnprocessableEntityHttpException when the subject path segment is blank, a 422
     * @throws ForgetIncomplete when a volunteer's hook failed; the key IS destroyed, re-run to
     *                          complete. Surfaced raw, a 500 an operator must see
     * @throws Throwable on a storage failure destroying the key or opening a home's transaction
     */
    #[Override]
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PrivacyForgetResource
    {
        $subject = trim((string) ($uriVariables['subject'] ?? ''));
        $input = $data; // the operation declares the input class; API Platform denormalized it

        // before anything on purpose: an anonymous caller learns nothing, not even key state
        $this->gate->assertOwnedIdentity('privacy_forget', $subject);

        if ($subject === '') {
            throw new UnprocessableEntityHttpException('The subject id must be a non-empty string — the value of the #[Personal] subject payload key.');
        }

        try {
            $outcome = $this->forgetter->forget($subject);
        } catch (ForgetIncomplete $e) {
            // the audit must say the decision was taken even though its read-model half stalled;
            // the raw failure then surfaces as the 500 the operator has to see
            $this->audit->record('privacy_forget', $subject, 'incomplete: '.$e->getMessage());

            throw $e;
        } catch (Throwable $e) {
            // the most irreversible verb of the surface: an outage mid-destruction must leave a
            // line, never a silent gap exactly where the trail matters most
            $this->audit->record('privacy_forget', $subject, 'failed: '.$e->getMessage());

            throw $e;
        }

        $why = trim((string) ($input->reason ?? ''));

        $this->audit->record('privacy_forget', $subject, sprintf(
            '%s%s',
            $outcome->keyDestroyed ? 'applied' : 'already',
            $why === '' ? '' : ' — '.$why,
        ));

        return PrivacyForgetResource::fromOutcome($subject, $outcome);
    }
}
