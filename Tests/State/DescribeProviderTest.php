<?php

declare(strict_types=1);

namespace Storm\ApiOps\Tests\State;

use ApiPlatform\Metadata\Get;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\ApiOps\Error\UnknownDescribeSection;
use Storm\ApiOps\State\DescribeProvider;
use Storm\Contracts\Chronicler\EventTypeMapper;
use Storm\Projector\Registry\ProjectionRegistry;
use Storm\Saga\Build\WorkflowRegistry;
use Storm\Symfony\Describe\StormDescriptor;

/**
 * The section filter is the whole behaviour of this provider, and it had no unit suite: the module's
 * mutation field never generated a mutant for it, because Infection only mutates covered code.
 */
final class DescribeProviderTest extends TestCase
{
    #[Test]
    public function no_section_serves_the_whole_document(): void
    {
        $document = $this->provider()->provide(new Get);

        self::assertNotNull($document->meta);
        self::assertNotNull($document->workflows);
        self::assertNotNull($document->projections);
        self::assertNotNull($document->health);
    }

    #[Test]
    public function a_named_section_serves_alon_e_and_the_others_stay_null(): void
    {
        // the point of asking for one: a caller after the workflow registry must not pay for the
        // schemas and the event types, and the shape must say which half it is holding
        $document = $this->provider()->provide(new Get, [], ['filters' => ['section' => 'workflows']]);

        self::assertNotNull($document->workflows);
        self::assertNull($document->meta);
        self::assertNull($document->event_types);
        self::assertNull($document->projections);
        self::assertNull($document->buses);
        self::assertNull($document->grants);
        self::assertNull($document->schemas);
        self::assertNull($document->health);
    }

    #[Test]
    #[Group('adversarial')]
    public function every_declared_section_can_be_asked_for_alone(): void
    {
        // the inventory is the contract: a section named in SECTIONS that the provider cannot serve
        // would answer an all-null document, which reads as "nothing to report" rather than a defect
        foreach (StormDescriptor::SECTIONS as $section) {
            $document = $this->provider()->provide(new Get, [], ['filters' => ['section' => $section]]);

            self::assertNotNull($document->{$section}, sprintf('section "%s" is declared and answers null', $section));

            // and every OTHER section is silent, which is the half that proves the filter narrows
            // rather than merely answers: a document that served two sections when one was named
            // would pass an assertion on the named one alone
            foreach (StormDescriptor::SECTIONS as $other) {
                if ($other !== $section) {
                    self::assertNull($document->{$other}, sprintf('asking "%s" also served "%s"', $section, $other));
                }
            }
        }
    }

    #[Test]
    #[Group('adversarial')]
    public function an_unknown_section_is_refused_and_lists_the_valid_names(): void
    {
        // refusing loudly is the point: a typo silently ignored would serve the WHOLE document to a
        // caller who asked for one slice, and the size of the answer is the only clue they would get
        $this->expectException(UnknownDescribeSection::class);
        $this->expectExceptionMessageMatches('/workflows/');

        $this->provider()->provide(new Get, [], ['filters' => ['section' => 'wokflows']]);
    }

    #[Test]
    #[Group('adversarial')]
    public function a_section_that_is_not_a_string_is_refused_by_its_type_name(): void
    {
        // `?section[]=meta` arrives as an array and can never name a section; the refusal quotes the
        // TYPE rather than casting it, so the message tells the caller what it actually sent
        $this->expectException(UnknownDescribeSection::class);
        $this->expectExceptionMessageMatches('/array/');

        $this->provider()->provide(new Get, [], ['filters' => ['section' => ['meta']]]);
    }

    #[Test]
    public function a_null_section_is_not_a_refusal_but_the_whole_document(): void
    {
        // an absent parameter and a parameter explicitly null are the same request here, and neither
        // is an error: the filter narrows, it never gates
        $document = $this->provider()->provide(new Get, [], ['filters' => ['section' => null]]);

        self::assertNotNull($document->meta);
    }

    private function provider(): DescribeProvider
    {
        return new DescribeProvider(new StormDescriptor(
            new ProjectionRegistry,
            $this->createStub(EventTypeMapper::class),
            new WorkflowRegistry,
            [],
            'test',
        ));
    }
}
