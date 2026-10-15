<?php

declare(strict_types=1);

use Pollora\Meta\Application\Services\MetaAccessor;
use Pollora\Meta\Application\Services\MetaSchemaBuilder;
use Pollora\Meta\Application\Services\MetaSchemaRepository;
use Pollora\Meta\Application\Services\MetaUiDrivers;
use Pollora\Meta\Domain\Contracts\MetaStoreInterface;
use Pollora\Meta\Domain\Contracts\MetaUiDriver;
use Pollora\Meta\Domain\Enums\Control;
use Pollora\Meta\Domain\Enums\MetaValueType;
use Pollora\Meta\Domain\Models\MetaDefinition;
use Pollora\Meta\Domain\Models\MetaSchema;
use Pollora\Meta\Domain\Services\MetaValueCaster;
use Pollora\Meta\Testing\InstantiatingContainer;
use Pollora\Meta\Testing\MetaUiDriverConformance;
use Pollora\Meta\Tests\Fixtures\Event;
use Pollora\Meta\Tests\Fixtures\MemberProfile;

/**
 * A driver that "builds" a field per meta it supports, the way an ACF or Meta
 * Box driver would, and records them.
 */
final class FieldRecordingDriver implements MetaUiDriver
{
    /** @var array<string, array{control: string|null, label: string|null, group: string|null, hints: array<string, mixed>}> */
    public array $fields = [];

    public function supports(MetaDefinition $definition): bool
    {
        return ! $definition->isStructured();
    }

    public function register(MetaSchema $schema): void
    {
        foreach ($schema->definitions as $definition) {
            $this->fields[$definition->key] = [
                'control' => $definition->control?->value,
                'label' => $definition->label,
                'group' => $definition->group,
                'hints' => $definition->hints['recording'] ?? [],
            ];
        }
    }
}

describe('declaring', function (): void {
    it('derives a neutral control from the type, unless one is given', function (): void {
        $definitions = MetaUiDriverConformance::schema()->definitions;

        expect(array_map(static fn (MetaDefinition $definition): ?Control => $definition->control, $definitions))->toBe([
            'text' => Control::Text,
            'textarea' => Control::Textarea,
            'richText' => Control::RichText,
            'number' => Control::Number,
            'decimal' => Control::Number,
            'toggle' => Control::Toggle,
            'date' => Control::Date,
            'dateTime' => Control::DateTime,
            'select' => Control::Select,
            'media' => Control::Media,
            'url' => Control::Url,
            'email' => Control::Email,
            'color' => Control::Color,
            'rows' => null,
            'list' => null,
        ])
            ->and($definitions['text']->group)->toBe('Basics')
            ->and($definitions['text']->hints)->toBe(['any-driver' => ['width' => 50]]);
    });
});

describe('drivers', function (): void {
    it('builds the fields of the meta a driver supports, and skips a schema left without any', function (): void {
        $driver = new FieldRecordingDriver;
        $drivers = new MetaUiDrivers(new InstantiatingContainer);
        $drivers->extend('recording', static fn (): MetaUiDriver => $driver);

        $drivers->build($drivers->driver('recording'), [MetaUiDriverConformance::schema()]);

        expect(array_keys($driver->fields))->not->toContain('rows', 'list')
            ->and($driver->fields['text'])->toBe(['control' => 'text', 'label' => 'Text', 'group' => 'Basics', 'hints' => []])
            ->and($driver->fields['select']['control'])->toBe('select');
    });

    it('resolves a driver registered by class, and refuses an unknown or a wrong one', function (): void {
        $drivers = new MetaUiDrivers(new InstantiatingContainer);
        $drivers->extend('recording', FieldRecordingDriver::class);
        $drivers->extend('wrong', static fn (): object => new stdClass);

        expect($drivers->driver('recording'))->toBeInstanceOf(FieldRecordingDriver::class)
            ->and($drivers->has('recording'))->toBeTrue()
            ->and(fn (): MetaUiDriver => $drivers->driver('acf'))->toThrow(InvalidArgumentException::class, 'No meta UI driver is named "acf"')
            ->and(fn (): MetaUiDriver => $drivers->driver('wrong'))->toThrow(InvalidArgumentException::class, 'does not implement');
    });

    it('checks a driver against the contract', function (): void {
        $throwing = new class implements MetaUiDriver
        {
            public function supports(MetaDefinition $definition): bool
            {
                return $definition->valueType === MetaValueType::String ?: throw new RuntimeException('no');
            }

            public function register(MetaSchema $schema): void {}
        };

        expect(MetaUiDriverConformance::check(new FieldRecordingDriver))->toBe([])
            ->and(MetaUiDriverConformance::check($throwing))->toContain('supports() throws for "number" (no): it must answer false instead.');
    });
});

describe('the facade service', function (): void {
    it('gives the schemas and registers drivers', function (): void {
        $repository = new MetaSchemaRepository;
        $repository->add($event = (new MetaSchemaBuilder)->build(Event::class));
        $repository->add($member = (new MetaSchemaBuilder)->build(MemberProfile::class));

        $drivers = new MetaUiDrivers(new InstantiatingContainer);
        $accessor = new MetaAccessor($repository, new MetaSchemaBuilder, Mockery::mock(MetaStoreInterface::class), new MetaValueCaster, drivers: $drivers);

        $accessor->extend('recording', FieldRecordingDriver::class);

        expect($accessor->schemas())->toBe([$event, $member])
            ->and($accessor->schemaFor('post', 'event'))->toBe([$event])
            ->and($accessor->schemaFor('user'))->toBe([$member])
            ->and($drivers->has('recording'))->toBeTrue();
    });
});
