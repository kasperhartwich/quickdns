<?php

declare(strict_types=1);

namespace QuickDns\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use QuickDns\BaseModel;
use QuickDns\Exceptions\MissingId;
use QuickDns\Group;
use QuickDns\Template;
use QuickDns\Zone;

final class ModelsTest extends TestCase
{
    public static function models(): array
    {
        return [[Zone::class], [Template::class], [Group::class]];
    }

    #[DataProvider('models')]
    public function test_models_are_final_and_readonly(string $class)
    {
        $reflection = new \ReflectionClass($class);

        $this->assertTrue($reflection->isFinal());
        $this->assertTrue($reflection->isReadOnly());
    }

    public function test_the_base_is_abstract()
    {
        $this->assertTrue((new \ReflectionClass(BaseModel::class))->isAbstract());
    }

    public function test_a_model_cannot_be_changed_in_place()
    {
        $zone = new Zone($this->quickDns(), 'example.dk', 1);

        $this->expectException(\Error::class);
        $zone->id = 2;
    }

    public function test_require_id()
    {
        $this->assertSame(1, (new Zone($this->quickDns(), 'example.dk', 1))->requireId());

        $this->expectException(MissingId::class);
        $this->expectExceptionMessage('Template is not created yet.');
        (new Template($this->quickDns(), 'standard'))->requireId();
    }

    public function test_create_returns_a_new_object_with_the_id()
    {
        $zone = new Zone($this->quickDns(['addzone-ok']), 'flyvende-agurk-pingvin.dk');

        $created = $zone->create();

        $this->assertNull($zone->id);
        $this->assertSame(17286, $created->id);
        $this->assertSame([], $created->templateIds, 'A new zone has no templates, and that is known.');
    }

    public function test_a_removed_property_names_its_replacement()
    {
        $group = new Group($this->quickDns(), 'kunder', 738);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Group::$updated was removed in 3.0: QuickDNS shows no time for a group. See UPGRADING.md.');
        $group->updated;
    }

    public function test_an_unknown_property_is_not_silently_null()
    {
        $zone = new Zone($this->quickDns(), 'example.dk');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Undefined property Zone::$nonsense');
        $zone->nonsense;
    }
}
