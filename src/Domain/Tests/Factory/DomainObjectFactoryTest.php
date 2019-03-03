<?php

declare(strict_types=1);

namespace MsgPhp\Domain\Tests\Factory;

use MsgPhp\Domain\DomainCollection;
use MsgPhp\Domain\Exception\InvalidClassException;
use MsgPhp\Domain\Factory\DomainObjectFactory;
use MsgPhp\Domain\Factory\DomainObjectFactoryInterface;
use MsgPhp\Domain\Tests\Fixtures\TestDomainId;
use PHPUnit\Framework\TestCase;

final class DomainObjectFactoryTest extends TestCase
{
    public function testCreate(): void
    {
        $object = (new DomainObjectFactory())->create(TestObject::class, ['argB' => 'foo', 'arg_a' => 'ignore', 0 => 'ignore', 'argA' => 1]);

        self::assertInstanceOf(TestObject::class, $object);
        self::assertSame(1, $object->a);
        self::assertSame('foo', $object->b);
    }

    public function testCreateDefaultsConstructorArguments(): void
    {
        $object = (new DomainObjectFactory())->create(TestObject::class);

        self::assertInstanceOf(TestObject::class, $object);
        self::assertNull($object->a);
        self::assertSame('default-b', $object->b);
    }

    public function testCreatePreservesContext(): void
    {
        $object = (new DomainObjectFactory())->create(TestObject::class, ['argB' => null]);

        self::assertInstanceOf(TestObject::class, $object);
        self::assertNull($object->b);
    }

    public function testCreateWithAlias(): void
    {
        $object = (new DomainObjectFactory([
            TestEmptyObject::class => TestExtendedEmptyObject::class,
        ]))->create(TestEmptyObject::class);

        self::assertInstanceOf(TestExtendedEmptyObject::class, $object);
    }

    public function testCreateWithDomainId(): void
    {
        $id = (new DomainObjectFactory())->create(TestDomainId::class, ['value' => 123]);

        self::assertInstanceOf(TestDomainId::class, $id);
        self::assertSame('123', $id->toString());
    }

    public function testCreateWithDomainCollection(): void
    {
        $collection = (new DomainObjectFactory())->create(DomainCollection::class, ['value' => [1, 2, 3]]);

        self::assertInstanceOf(DomainCollection::class, $collection);
        self::assertSame([1, 2, 3], iterator_to_array($collection));
    }

    public function testCreateWithUnknownObject(): void
    {
        $factory = new DomainObjectFactory();

        $this->expectException(InvalidClassException::class);

        $factory->create(TestUnknownObject::class);
    }

    public function testCreateWithUnknownNestedObject(): void
    {
        $factory = new DomainObjectFactory();

        self::assertInstanceOf(TestKnownObject::class, $factory->create(TestKnownObject::class));
        self::assertInstanceOf(TestKnownObject::class, $factory->create(TestKnownObject::class, ['unknown' => 'foo']));

        $this->expectException(\TypeError::class);

        $factory->create(TestKnownObject::class, ['arg' => []]);
    }

    public function testCreateWithoutConstructor(): void
    {
        self::assertInstanceOf(TestEmptyObject::class, (new DomainObjectFactory())->create(TestEmptyObject::class, ['arg']));
    }

    public function testCreateWithPrivateConstructor(): void
    {
        $factory = new DomainObjectFactory();

        $this->expectException(\Error::class);

        $factory->create(TestPrivateObject::class, ['arg']);
    }

    public function testNestedCreate(): void
    {
        $object = (new DomainObjectFactory())->create(TestNestedObject::class, [
            'test' => ['argA' => 'nested_a', 'argB' => 'nested_b', 'arg_b' => 'ignore'],
            'self' => ['test' => ['argA' => 'foo', 'argB' => 'bar'], 'other' => $other = new TestObject(1, 2)],
        ]);

        self::assertInstanceOf(TestNestedObject::class, $object);
        self::assertInstanceOf(TestObject::class, $object->test);
        self::assertSame('nested_a', $object->test->a);
        self::assertSame('nested_b', $object->test->b);
        self::assertInstanceOf(TestNestedObject::class, $object->self);
        self::assertSame('foo', $object->self->test->a);
        self::assertSame('bar', $object->self->test->b);
        self::assertSame($other, $object->self->other);
    }

    public function testNestedCreateWithoutRequiredContext(): void
    {
        $factory = new DomainObjectFactory();

        $this->expectException(\LogicException::class);

        $factory->create(TestNestedObject::class);
    }

    public function testNestedCreateWithNestedFactory(): void
    {
        $nestedFactory = $this->createMock(DomainObjectFactoryInterface::class);
        $nestedFactory->expects(self::once())
            ->method('create')
            ->willReturn($nested = new TestObject(1, 2))
        ;

        $factory = new DomainObjectFactory();
        $factory->setNestedFactory($nestedFactory);

        self::assertInstanceOf(TestNestedObject::class, $object = $factory->create(TestNestedObject::class, ['test' => null]));
        self::assertSame($nested, $object->test);
    }

    public function testNestedCreateWithInvalidNestedFactory(): void
    {
        $nestedFactory = $this->createMock(DomainObjectFactoryInterface::class);
        $nestedFactory->expects(self::once())
            ->method('create')
            ->willThrowException(new \RuntimeException())
        ;

        $factory = new DomainObjectFactory();
        $factory->setNestedFactory($nestedFactory);

        $this->expectException(\RuntimeException::class);

        $factory->create(TestNestedObject::class, ['test' => ['irrelevant']]);
    }

    public function testReference(): void
    {
        $reference = (new DomainObjectFactory())->reference(TestReferenceObject::class, ['fieldA' => 1, 'fieldB' => 2]);

        self::assertInstanceOf(TestReferenceObject::class, $reference);
        self::assertSame([1, 2], $reference->get());
    }

    public function testReferenceWithUnknownClass(): void
    {
        $factory = new DomainObjectFactory();

        $this->expectException(InvalidClassException::class);

        $factory->reference(TestUnknownObject::class);
    }

    public function testGetClass(): void
    {
        $factory = new DomainObjectFactory([
            TestEmptyObject::class => TestExtendedEmptyObject::class,
        ]);

        self::assertSame(TestExtendedEmptyObject::class, $factory->getClass(TestEmptyObject::class));
        self::assertSame(\stdClass::class, $factory->getClass(\stdClass::class));
    }
}

class TestObject
{
    public $a = 'default-a';
    public $b;

    public function __construct($argA, $argB = 'default-b')
    {
        $this->a = $argA;
        $this->b = $argB;
    }
}

class TestNestedObject
{
    public $test;
    public $self;
    public $other;

    public function __construct(TestObject $test, ?self $self, ?TestObject $other)
    {
        $this->test = $test;
        $this->self = $self;
        $this->other = $other;
    }
}

class TestEmptyObject
{
}

class TestExtendedEmptyObject extends TestEmptyObject
{
}

class TestPrivateObject
{
    private function __construct()
    {
    }
}

class TestKnownObject
{
    public function __construct(TestUnknownObject $arg = null)
    {
    }
}

class TestReferenceObject
{
    private $fieldA;
    private $fieldB = 'default B';

    public function __construct()
    {
        throw new \BadMethodCallException();
    }

    public function get(): array
    {
        return [$this->fieldA, $this->fieldB];
    }
}
