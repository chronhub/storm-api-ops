<?php

declare(strict_types=1);

namespace Storm\ApiOps\Tests\State;

use ApiPlatform\Metadata\Post;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Storm\ApiOps\Error\AnonymousMutationRefused;
use Storm\ApiOps\OpsActorGate;
use Storm\ApiOps\OpsAuditLog;
use Storm\ApiOps\Resource\ForgetSubjectInput;
use Storm\ApiOps\State\PrivacyForgetProcessor;
use Storm\ApiOps\Tests\Fixture\RecordingLog;
use Storm\Contracts\Serializer\CipherKeyStore;
use Storm\Ledger\Crypto\SubjectForgetter;
use Storm\Ledger\Exception\ForgetIncomplete;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * The HTTP half of the erasure, which had no unit suite: the most irreversible verb of the surface,
 * and its audit line is the whole reason this processor exists beside the assembly it delegates to.
 *
 * Nothing is destroyed here. The key store is a double, so the write path is exercised for the
 * DECISIONS this class owns and for none of the effects the Ledger's own suite proves.
 */
final class PrivacyForgetProcessorTest extends TestCase
{
    #[Test]
    #[Group('adversarial')]
    public function an_anonymous_caller_is_refused_before_any_key_is_touched(): void
    {
        // before anything on purpose: an unnamed caller learns nothing, not even whether a subject
        // still has a key
        $keys = $this->createMock(CipherKeyStore::class);
        $keys->expects($this->never())->method('destroy');

        $this->expectException(AnonymousMutationRefused::class);

        $this->processor($keys, anonymous: false)->process(new ForgetSubjectInput, new Post, ['subject' => 'cust-1']);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_blank_subject_is_refused_after_the_gate_and_before_the_forget(): void
    {
        // the order is the assertion: a 422 handed to an unnamed caller would tell it the surface
        // read its input, and a forget on an empty subject would destroy nothing while reporting
        $keys = $this->createMock(CipherKeyStore::class);
        $keys->expects($this->never())->method('destroy');

        $this->expectException(UnprocessableEntityHttpException::class);

        $this->processor($keys)->process(new ForgetSubjectInput, new Post, ['subject' => '   ']);
    }

    #[Test]
    public function a_destroyed_key_is_audited_as_applied(): void
    {
        $recorder = new RecordingLog;

        $answer = $this->processor($this->keys(destroyed: true), audit: new OpsAuditLog($recorder))
            ->process(new ForgetSubjectInput, new Post, ['subject' => 'cust-1']);

        self::assertSame('cust-1', $answer->subject);
        self::assertTrue($answer->keyDestroyed);
        self::assertSame('applied', $this->outcome($recorder));
    }

    #[Test]
    #[Group('adversarial')]
    public function an_idempotent_re_run_is_audited_as_already_and_never_as_applied(): void
    {
        // the two outcomes are what an auditor reads to tell the erasure that HAPPENED from the
        // re-run that found the work done; folding them would make a trail unable to answer when
        $recorder = new RecordingLog;

        $answer = $this->processor($this->keys(destroyed: false), audit: new OpsAuditLog($recorder))
            ->process(new ForgetSubjectInput, new Post, ['subject' => 'cust-1']);

        self::assertFalse($answer->keyDestroyed);
        self::assertSame('already', $this->outcome($recorder));
    }

    #[Test]
    public function the_reason_given_rides_the_audit_line(): void
    {
        $recorder = new RecordingLog;

        $this->processor($this->keys(destroyed: true), audit: new OpsAuditLog($recorder))
            ->process(new ForgetSubjectInput(reason: 'GDPR request 42'), new Post, ['subject' => 'cust-1']);

        self::assertSame('applied — GDPR request 42', $this->outcome($recorder));
    }

    #[Test]
    #[Group('adversarial')]
    public function a_reason_of_whitespace_is_no_reason_at_all(): void
    {
        // an outcome ending on a dangling separator reads as a truncated line, which is worse than
        // one that never claimed a reason
        $recorder = new RecordingLog;

        $this->processor($this->keys(destroyed: true), audit: new OpsAuditLog($recorder))
            ->process(new ForgetSubjectInput(reason: '   '), new Post, ['subject' => 'cust-1']);

        self::assertSame('applied', $this->outcome($recorder));
    }

    #[Test]
    #[Group('adversarial')]
    public function a_subject_is_trimmed_before_it_is_forgotten_and_not_only_before_it_is_shown(): void
    {
        // a subject pasted with its trailing space names a key that does not exist, so the run
        // reports an idempotent re-forget over a subject still perfectly readable
        $seen = [];
        $keys = $this->createStub(CipherKeyStore::class);
        $keys->method('destroy')->willReturnCallback(static function (string $subject) use (&$seen): bool {
            $seen[] = $subject;

            return true;
        });

        $answer = $this->processor($keys)->process(new ForgetSubjectInput, new Post, ['subject' => '  cust-1  ']);

        self::assertSame(['cust-1'], $seen);
        self::assertSame('cust-1', $answer->subject);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_stalled_forget_is_audited_as_incomplete_and_still_surfaces(): void
    {
        // the decision was TAKEN even though its read-model half stalled: the key is destroyed, and
        // an audit that stayed silent here would hide the one state an operator must act on. The
        // raw failure then surfaces as the 500 they have to see.
        $recorder = new RecordingLog;
        $keys = $this->createStub(CipherKeyStore::class);
        $keys->method('destroy')->willThrowException(
            ForgetIncomplete::projectionFailed('cust-1', 'customer_360', new RuntimeException('the home rolled back')),
        );

        try {
            $this->processor($keys, audit: new OpsAuditLog($recorder))
                ->process(new ForgetSubjectInput, new Post, ['subject' => 'cust-1']);
            self::fail('an incomplete forget must surface');
        } catch (ForgetIncomplete $e) {
            // the recovery instruction travels INTO the line: it is the one message saying the key
            // IS destroyed and a re-run completes the rest
            self::assertSame('incomplete: '.$e->getMessage(), $this->outcome($recorder));
            self::assertStringContainsString('is INCOMPLETE', $e->getMessage());
        }
    }

    #[Test]
    #[Group('adversarial')]
    public function an_outage_mid_destruction_still_leaves_a_line(): void
    {
        // the most irreversible verb of the surface: a silent gap exactly where the trail matters
        // most is the failure this catch exists for, and `failed` is not `incomplete`
        $recorder = new RecordingLog;
        $keys = $this->createStub(CipherKeyStore::class);
        $keys->method('destroy')->willThrowException(new RuntimeException('the key store is unreachable'));

        try {
            $this->processor($keys, audit: new OpsAuditLog($recorder))
                ->process(new ForgetSubjectInput, new Post, ['subject' => 'cust-1']);
            self::fail('a storage failure must surface');
        } catch (RuntimeException $e) {
            self::assertSame('failed: the key store is unreachable', $this->outcome($recorder));
            self::assertStringNotContainsString('incomplete:', $this->outcome($recorder));
            self::assertSame('the key store is unreachable', $e->getMessage());
        }
    }

    #[Test]
    public function a_forget_with_no_projector_wiring_reports_an_empty_pair_of_lists(): void
    {
        // a standalone Ledger, or a query-only app, has no registry and no lanes: the forget
        // degrades to the key destruction with an empty report rather than refusing
        $answer = $this->processor($this->keys(destroyed: true))
            ->process(new ForgetSubjectInput, new Post, ['subject' => 'cust-1']);

        self::assertSame([], $answer->touched);
        self::assertSame([], $answer->untouched);
    }

    private function outcome(RecordingLog $recorder): string
    {
        return implode("\n", array_map(
            static fn (array $record): string => (string) ($record['context']['outcome'] ?? ''),
            $recorder->records,
        ));
    }

    private function keys(bool $destroyed): CipherKeyStore
    {
        $keys = $this->createStub(CipherKeyStore::class);
        $keys->method('destroy')->willReturn($destroyed);

        return $keys;
    }

    private function processor(CipherKeyStore $keys, bool $anonymous = true, ?OpsAuditLog $audit = null): PrivacyForgetProcessor
    {
        $log = $audit ?? new OpsAuditLog(new RecordingLog);

        return new PrivacyForgetProcessor(
            new SubjectForgetter($keys),
            $log,
            new OpsActorGate($log, null, allowAnonymous: $anonymous),
        );
    }
}
