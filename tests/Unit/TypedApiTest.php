<?php

declare(strict_types=1);

namespace QuickDns\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase as BaseTestCase;
use QuickDns\BaseModel;
use QuickDns\Group;
use QuickDns\QuickDns;
use QuickDns\Record;
use QuickDns\RecordSet;
use QuickDns\RecordType;
use QuickDns\Template;
use QuickDns\Testing\FakeQuickDns;
use QuickDns\Zone;

/**
 * 3.0 promises a typed API. This is the promise, checked: nothing public may go untyped again,
 * and every file declares strict types.
 */
final class TypedApiTest extends BaseTestCase
{
    /**
     * @return array<string, array{class-string}>
     */
    public static function classes(): array
    {
        return array_map(fn (string $class) => [$class], [
            QuickDns::class => QuickDns::class,
            BaseModel::class => BaseModel::class,
            Zone::class => Zone::class,
            Template::class => Template::class,
            Group::class => Group::class,
            Record::class => Record::class,
            RecordSet::class => RecordSet::class,
            RecordType::class => RecordType::class,
            FakeQuickDns::class => FakeQuickDns::class,
        ]);
    }

    #[DataProvider('classes')]
    public function test_every_public_method_is_typed(string $class)
    {
        $untyped = [];
        foreach ((new \ReflectionClass($class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() !== $class || $method->isInternal()) {
                continue;
            }
            if (! $method->hasReturnType() && $method->getName() !== '__construct') {
                $untyped[] = $method->getName().'(): no return type';
            }
            foreach ($method->getParameters() as $parameter) {
                if (! $parameter->hasType()) {
                    $untyped[] = $method->getName().'($'.$parameter->getName().')';
                }
            }
        }

        $this->assertSame([], $untyped);
    }

    /**
     * The models' public properties are typed in the pull request that fixes what their types
     * actually are: Zone::$id is a string behind an int docblock, Template::$zones an int behind
     * an array one. Until then they are listed here, and this test fails the moment one is typed
     * and not taken off the list.
     */
    private const UNTYPED_UNTIL_THE_MODELS_ARE_REWRITTEN = [
        BaseModel::class => ['$id', '$updated'],
        Zone::class => ['$domain', '$templates', '$groups'],
        Template::class => ['$name', '$zones', '$groups'],
        Group::class => ['$name', '$members'],
    ];

    #[DataProvider('classes')]
    public function test_every_property_is_typed(string $class)
    {
        $untyped = [];
        foreach ((new \ReflectionClass($class))->getProperties() as $property) {
            if ($property->getDeclaringClass()->getName() === $class && ! $property->hasType()) {
                $untyped[] = '$'.$property->getName();
            }
        }

        $this->assertSame(self::UNTYPED_UNTIL_THE_MODELS_ARE_REWRITTEN[$class] ?? [], $untyped);
    }

    public function test_every_file_declares_strict_types()
    {
        $without = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__.'/../../src')) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            if (! str_contains((string) file_get_contents($file->getPathname()), 'declare(strict_types=1);')) {
                $without[] = $file->getFilename();
            }
        }

        $this->assertSame([], $without);
    }
}
