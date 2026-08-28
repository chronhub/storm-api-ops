<?php

declare(strict_types=1);

namespace Storm\ApiOps\Tests\View;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\ApiOps\View\SagaDeclaration;

final class SagaDeclarationTest extends TestCase
{
    #[Test]
    public function a_declared_spawn_no_child_matches_is_reported_as_never_taken(): void
    {
        // the comparison the screen exists for: what was possible against what happened
        $declaration = SagaDeclaration::forType($this->workflows('transfer', [
            ['slot' => 'leg', 'workflow' => 'settlement_leg', 'awaited_by' => 'await_legs'],
            ['slot' => 'audit', 'workflow' => 'audit_trail', 'awaited_by' => null],
        ]), 'transfer');

        $never = $declaration->neverTaken([['workflow_type' => 'settlement_leg', 'correlation_id' => 'c1', 'status' => 'done']]);

        self::assertCount(1, $never);
        self::assertSame('audit', $never[0]['slot']);
    }

    #[Test]
    public function every_spawn_taken_leaves_nothing_to_report(): void
    {
        $declaration = SagaDeclaration::forType($this->workflows('transfer', [
            ['slot' => 'leg', 'workflow' => 'settlement_leg', 'awaited_by' => null],
        ]), 'transfer');

        self::assertSame([], $declaration->neverTaken([['workflow_type' => 'settlement_leg', 'correlation_id' => 'c1', 'status' => 'done']]));
    }

    #[Test]
    #[Group('adversarial')]
    public function a_saga_package_that_is_absent_degrades_with_the_reason_it_gave(): void
    {
        // the describe section keeps a stable shape and says WHY it is empty; the screen carries
        // that sentence rather than inventing one
        $declaration = SagaDeclaration::forType([
            'available' => false,
            'reason' => 'the opt-in Saga package is not installed, so no workflow registry is wired',
            'definitions' => [],
        ], 'transfer');

        self::assertFalse($declaration->available);
        self::assertStringContainsString('not installed', $declaration->reason ?? '');
    }

    #[Test]
    #[Group('adversarial')]
    public function a_type_the_registry_does_not_know_is_an_answer_and_not_a_failure(): void
    {
        // an instance may predate a rename, or the store may have been written by another app
        $declaration = SagaDeclaration::forType($this->workflows('transfer', []), 'card_authorization');

        self::assertFalse($declaration->available);
        self::assertStringContainsString('no declaration is registered', $declaration->reason ?? '');
    }

    #[Test]
    #[Group('adversarial')]
    public function a_malformed_describe_document_never_throws_at_a_screen(): void
    {
        self::assertFalse(SagaDeclaration::forType(null, 'transfer')->available);
        self::assertFalse(SagaDeclaration::forType(['available' => true, 'definitions' => 'not a list'], 'transfer')->available);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_spawn_missing_its_keys_is_dropped_rather_than_half_rendered(): void
    {
        $declaration = SagaDeclaration::forType($this->workflows('transfer', [
            ['slot' => 'leg'],
            ['slot' => 'audit', 'workflow' => 'audit_trail', 'awaited_by' => null],
        ]), 'transfer');

        self::assertCount(1, $declaration->spawns);
        self::assertSame('audit', $declaration->spawns[0]['slot']);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_document_that_never_says_it_is_available_is_treated_as_unavailable(): void
    {
        // the absent key is the dangerous one, and the fixture has to make it VISIBLE: with the
        // definitions carrying this very type, a document read as available would answer TRUE, while
        // an empty definitions list would answer false either way and prove nothing.
        $declaration = SagaDeclaration::forType([
            'definitions' => [['name' => 'transfer', 'version' => 1, 'spawns' => []]],
        ], 'transfer');

        self::assertFalse($declaration->available);
        self::assertStringContainsString('not available on this installation', $declaration->reason ?? '');
    }

    #[Test]
    #[Group('adversarial')]
    public function a_spawn_that_is_not_even_an_array_is_dropped(): void
    {
        // @phpstan-ignore argument.type (a describe document is decoded json, so a spawn that is not an array is a runtime shape the analyser cannot forbid; it is the case under test)
        $declaration = SagaDeclaration::forType($this->workflows('transfer', ['not an array']), 'transfer');

        self::assertSame([], $declaration->spawns);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_spawn_without_a_slot_is_dropped_like_one_without_a_workflow(): void
    {
        // both halves of the guard, because a spawn half-shaped would render a row naming nothing
        $declaration = SagaDeclaration::forType($this->workflows('transfer', [
            ['workflow' => 'audit_trail', 'awaited_by' => null],
        ]), 'transfer');

        self::assertSame([], $declaration->spawns);
    }

    #[Test]
    public function an_awaited_by_that_is_not_a_string_becomes_null_rather_than_reaching_the_page(): void
    {
        $declaration = SagaDeclaration::forType($this->workflows('transfer', [
            ['slot' => 'audit', 'workflow' => 'audit_trail', 'awaited_by' => 42],
        ]), 'transfer');

        self::assertNull($declaration->spawns[0]['awaited_by']);
    }

    /**
     * @param  list<array<string, mixed>>  $spawns
     * @return array<string, mixed>
     */
    private function workflows(string $name, array $spawns): array
    {
        return [
            'available' => true,
            'reason' => null,
            'definitions' => [['name' => $name, 'version' => 1, 'spawns' => $spawns]],
        ];
    }
}
