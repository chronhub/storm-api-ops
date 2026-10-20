<?php

declare(strict_types=1);

namespace Storm\ApiOps\Tests\State;

use ApiPlatform\Metadata\ApiResource;
use PhpToken;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Storm\ApiOps\Error\AnonymousMutationRefused;
use Storm\ApiOps\Error\AnonymousReadRefused;
use Storm\ApiOps\Error\ProjectionNotFound;
use Storm\ApiOps\Error\SagaCancelRefused;
use Storm\ApiOps\Error\SagaCommandNotFound;
use Storm\ApiOps\Error\SagaPauseRefused;
use Storm\ApiOps\Error\SagaRedriveRefused;
use Storm\ApiOps\Resource\ProjectionResource;
use Storm\ApiOps\Resource\SagaResource;
use Storm\ApiOps\State\ProjectionActionProcessor;
use Storm\ApiOps\State\SagaCancelProcessor;
use Storm\ApiOps\State\SagaPauseProcessor;
use Storm\ApiOps\State\SagaRedriveProcessor;
use Storm\Saga\Exception\StaleWorkflowInstance;
use Storm\Saga\Exception\WorkflowNotFound;

use function array_filter;
use function array_keys;
use function array_values;
use function file_get_contents;
use function ltrim;
use function str_starts_with;
use function strpos;
use function strrchr;
use function substr;
use function trim;

/**
 * Binds the refusals a mutating processor audits as `refused:` to the 4xx answers of its resource.
 *
 * The union is the type list of every catch clause whose block writes a `refused: ` literal, read
 * from the processor's tokens. The two sets cover each other:
 *
 * - A type of the union answers a 4xx, or the caller reads a 500 under a `refused:` line
 * - A 4xx of the resource is in the union or named here as raised outside the processor's mutation,
 *   so a new 4xx cannot fall into the wiring or failure arm without its refusal line
 */
final class NamedRefusalUnionTest extends TestCase
{
    /**
     * @param  class-string  $processor
     * @param  class-string  $resource
     * @param  array<class-string, string>  $raisedElsewhere  the 4xx answers the processor's mutation never
     *                                                        raises, each with where it comes from
     */
    #[Test]
    #[DataProvider('processorsAndTheirResources')]
    public function the_named_refusals_and_the_4xx_answers_cover_each_other(string $processor, string $resource, array $raisedElsewhere): void
    {
        $union = self::refusedUnion((string) file_get_contents((string) new ReflectionClass($processor)->getFileName()));

        // an empty read would let the comparison below hold on the exclusions alone
        $this->assertNotSame([], $union, $processor.' has no catch clause that writes refused:');
        $this->assertEqualsCanonicalizing(self::clientErrors($resource), [...$union, ...array_keys($raisedElsewhere)]);
    }

    /**
     * @return iterable<string, array{class-string, class-string, array<class-string, string>}>
     */
    public static function processorsAndTheirResources(): iterable
    {
        yield 'projection actions' => [ProjectionActionProcessor::class, ProjectionResource::class, [
            AnonymousMutationRefused::class => 'the actor gate, before the processor reads anything',
            AnonymousReadRefused::class => 'the read side of the resource, served by its provider',
            ProjectionNotFound::class => 'outside the mutation, after its own refused line or the read-back',
        ]];

        yield 'saga cancel' => [SagaCancelProcessor::class, SagaResource::class, [
            AnonymousMutationRefused::class => 'the actor gate, before the engine',
            AnonymousReadRefused::class => 'the read side of the resource, served by its provider',
            SagaCommandNotFound::class => 'after the applied line, when a prune wins the read-back',
            SagaRedriveRefused::class => 'the redrive operation of the resource',
            SagaPauseRefused::class => 'the pause and resume operations of the resource',
        ]];

        yield 'saga pause' => [SagaPauseProcessor::class, SagaResource::class, [
            AnonymousMutationRefused::class => 'the actor gate, before the store',
            AnonymousReadRefused::class => 'the read side of the resource, served by its provider',
            WorkflowNotFound::class => 'the engine, under the cancel operation of the resource',
            StaleWorkflowInstance::class => 'the engine, under the cancel operation of the resource',
            SagaCancelRefused::class => 'the cancel operation of the resource',
            SagaCommandNotFound::class => 'after the applied line, when a prune wins the read-back',
            SagaRedriveRefused::class => 'the redrive operation of the resource',
        ]];

        yield 'saga redrive' => [SagaRedriveProcessor::class, SagaResource::class, [
            AnonymousMutationRefused::class => 'the actor gate, before the outbox',
            AnonymousReadRefused::class => 'the read side of the resource, served by its provider',
            WorkflowNotFound::class => 'the engine, under the cancel operation of the resource',
            StaleWorkflowInstance::class => 'the engine, under the cancel operation of the resource',
            SagaCancelRefused::class => 'the cancel operation of the resource',
            SagaPauseRefused::class => 'the pause and resume operations of the resource',
        ]];
    }

    #[Test]
    public function the_union_resolves_every_spelling_and_keeps_only_the_refusing_clauses(): void
    {
        $source = <<<'PHP'
            <?php
            namespace Storm\ApiOps\State;

            use Storm\ApiOps\Error\SagaCancelRefused;
            use Storm\Saga\Exception as Saga;

            $record = function () use ($audit): void {};
            try {
            } catch (SagaCancelRefused|Saga\WorkflowNotFound|\Storm\Saga\Exception\StaleWorkflowInstance $e) {
                $audit->record('cancel', $subject, 'refused: '.$e->getMessage());
            } catch (LocalDecline $e) {
                if ($e !== null) {
                    $audit->record('cancel', $subject, 'refused: '.$e->getMessage());
                }
            } catch (Throwable $e) {
                // catch (Mentioned $e) { 'refused: ' }
                $audit->record('cancel', $subject, 'failed: '.$e->getMessage());
            }
            PHP;

        $this->assertSame([
            'Storm\ApiOps\Error\SagaCancelRefused',
            'Storm\Saga\Exception\WorkflowNotFound',
            'Storm\Saga\Exception\StaleWorkflowInstance',
            'Storm\ApiOps\State\LocalDecline',
        ], self::refusedUnion($source));
    }

    /**
     * @return list<string>
     */
    private static function refusedUnion(string $source): array
    {
        $tokens = array_values(array_filter(PhpToken::tokenize($source), static fn (PhpToken $token): bool => ! $token->isIgnorable()));
        $namespace = '';
        $imports = [];
        $union = [];

        foreach ($tokens as $i => $token) {
            if ($token->is(T_NAMESPACE)) {
                $namespace = $tokens[$i + 1]->text;
            } elseif ($token->is(T_USE) && $tokens[$i + 1]->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
                $name = ltrim($tokens[$i + 1]->text, '\\');
                $imports[$tokens[$i + 2]->is(T_AS) ? $tokens[$i + 3]->text : self::lastSegment($name)] = $name;
            } elseif ($token->is(T_CATCH)) {
                $types = [];
                for ($j = $i + 2; $tokens[$j]->text !== ')'; $j++) {
                    if ($tokens[$j]->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
                        $types[] = self::resolve($tokens[$j]->text, $namespace, $imports);
                    }
                }

                if (self::blockWritesRefused($tokens, $j + 1)) {
                    $union = [...$union, ...$types];
                }
            }
        }

        return $union;
    }

    /**
     * @param  list<PhpToken>  $tokens
     */
    private static function blockWritesRefused(array $tokens, int $open): bool
    {
        $depth = 0;
        for ($k = $open; isset($tokens[$k]); $k++) {
            $depth += match ($tokens[$k]->text) {
                '{' => 1,
                '}' => -1,
                default => 0,
            };

            if ($depth === 0) {
                return false;
            }

            if ($tokens[$k]->is(T_CONSTANT_ENCAPSED_STRING) && str_starts_with(trim($tokens[$k]->text, '\'"'), 'refused: ')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, string>  $imports
     */
    private static function resolve(string $name, string $namespace, array $imports): string
    {
        if (str_starts_with($name, '\\')) {
            return ltrim($name, '\\');
        }

        $separator = strpos($name, '\\');
        $head = $separator === false ? $name : substr($name, 0, $separator);

        if (isset($imports[$head])) {
            return $imports[$head].($separator === false ? '' : substr($name, $separator));
        }

        return $namespace.'\\'.$name;
    }

    private static function lastSegment(string $name): string
    {
        return substr((string) strrchr('\\'.$name, '\\'), 1);
    }

    /**
     * @param  class-string  $resource
     * @return list<string>
     */
    private static function clientErrors(string $resource): array
    {
        $declared = new ReflectionClass($resource)->getAttributes(ApiResource::class)[0]->newInstance()->getExceptionToStatus() ?? [];

        return array_keys(array_filter($declared, static fn (int $status): bool => $status >= 400 && $status < 500));
    }
}
