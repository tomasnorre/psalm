<?php
namespace Psalm\Tests;

use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use Psalm\Codebase;
use Psalm\Context;
use Psalm\Plugin\EventHandler\AfterClassLikeVisitInterface;
use Psalm\Plugin\EventHandler\Event\AfterClassLikeVisitEvent;
use Psalm\PluginRegistrationSocket;
use Psalm\Tests\Internal\Provider\ClassLikeStorageInstanceCacheProvider;
use Psalm\Type;

use function array_map;
use function array_values;
use function get_class;

class CodebaseTest extends TestCase
{
    /** @var Codebase */
    private $codebase;

    public function setUp() : void
    {
        parent::setUp();
        $this->codebase = $this->project_analyzer->getCodebase();
    }

    /**
     * @test
     * @dataProvider typeContainments
     *
     */
    public function isTypeContainedByType(string $input, string $container, bool $expected): void
    {
        $input = Type::parseString($input);
        $container = Type::parseString($container);

        $this->assertSame(
            $expected,
            $this->codebase->isTypeContainedByType($input, $container),
            'Expected ' . $input->getId() . ($expected ? ' ' : ' not ')
            . 'to be contained in ' . $container->getId()
        );
    }

    /** @return iterable<int,array{string,string,bool}> */
    public function typeContainments()
    {
        yield ['int', 'int|string', true];
        yield ['int|string', 'int', false];

        // This fails with 'could not get class storage' :(

        // yield ['RuntimeException', 'Exception', true];
        // yield ['Exception', 'RuntimeException', false];
    }

    /**
     * @test
     * @dataProvider typeIntersections
     *
     */
    public function canTypeBeContainedByType(string $input, string $container, bool $expected): void
    {
        $input = Type::parseString($input);
        $container = Type::parseString($container);

        $this->assertSame(
            $expected,
            $this->codebase->canTypeBeContainedByType($input, $container),
            'Expected ' . $input->getId() . ($expected ? ' ' : ' not ')
            . 'to be contained in ' . $container->getId()
        );
    }

    /** @return iterable<int,array{string,string,bool}> */
    public function typeIntersections()
    {
        yield ['int', 'int|string', true];
        yield ['int|string', 'int', true];
        yield ['int|string', 'string|float', true];
        yield ['int', 'string', false];
        yield ['int|string', 'array|float', false];
    }

    /**
     * @test
     * @dataProvider iterableParams
     *
     * @param array{string,string} $expected
     *
     */
    public function getKeyValueParamsForTraversableObject(string $input, array $expected): void
    {
        [$input] = array_values(Type::parseString($input)->getAtomicTypes());

        $expected_key_type = Type::parseString($expected[0]);
        $expected_value_type = Type::parseString($expected[1]);

        $actual = $this->codebase->getKeyValueParamsForTraversableObject($input);

        $this->assertTrue(
            $expected_key_type->equals($actual[0]),
            'Expected ' . $input->getId() . ' to have ' . $expected_key_type
            . ' but got ' . $actual[0]->getId()
        );

        $this->assertTrue(
            $expected_value_type->equals($actual[1]),
            'Expected ' . $input->getId() . ' to have ' . $expected_value_type
            . ' but got ' . $actual[1]->getId()
        );
    }

    /** @return iterable<int,array{string,array{string,string}}> */
    public function iterableParams()
    {
        yield ['iterable<int,string>', ['int', 'string']];
        yield ['iterable<int|string,bool|float>', ['int|string', 'bool|float']];
    }

    /**
     * @test
     *
     */
    public function customMetadataIsPersisted(): void
    {
        $this->addFile(
            'somefile.php',
            '<?php
                namespace Psalm\CurrentTest;
                abstract class A {}
                interface I {}
                class C extends A implements I
                {
                    /** @var string */
                    private $prop = "";

                    /** @return void */
                    public function m(int $_i = 1) {}
                }
            '
        );
        $hook = new class implements AfterClassLikeVisitInterface {
            /**
             * @return void
             */
            public static function afterClassLikeVisit(AfterClassLikeVisitEvent $event)
            {
                $stmt = $event->getStmt();
                $storage = $event->getStorage();
                $codebase = $event->getCodebase();
                if ($storage->name === 'Psalm\\CurrentTest\\C' && $stmt instanceof Class_) {
                    $storage->custom_metadata['fqcn'] = (string)($stmt->namespacedName ?? $stmt->name);
                    $storage->custom_metadata['extends'] = $stmt->extends instanceof Name
                        ? (string)$stmt->extends->getAttribute('resolvedName')
                        : '';
                    $storage->custom_metadata['implements'] = array_map(
                        function (Name $aspect): string {
                            return (string)$aspect->getAttribute('resolvedName');
                        },
                        $stmt->implements
                    );
                    $storage->custom_metadata['a'] = 'b';
                    $storage->methods['m']->custom_metadata['c'] = 'd';
                    $storage->properties['prop']->custom_metadata['e'] = 'f';
                    $storage->methods['m']->params[0]->custom_metadata['g'] = 'h';
                    $codebase->file_storage_provider->get('somefile.php')->custom_metadata['i'] = 'j';
                }
            }
        };
        (new PluginRegistrationSocket($this->codebase->config, $this->codebase))
            ->registerHooksFromClass(get_class($hook));
        $this->codebase->classlike_storage_provider->cache = new ClassLikeStorageInstanceCacheProvider;

        $this->analyzeFile('somefile.php', new Context);

        $fixtureNamespace = 'Psalm\\CurrentTest\\';
        $this->codebase->classlike_storage_provider->remove($fixtureNamespace . 'C');
        $this->codebase->exhumeClassLikeStorage($fixtureNamespace . 'C', 'somefile.php');

        $class_storage = $this->codebase->classlike_storage_provider->get($fixtureNamespace . 'C');
        $file_storage = $this->codebase->file_storage_provider->get('somefile.php');

        self::assertSame($fixtureNamespace . 'C', $class_storage->custom_metadata['fqcn']);
        self::assertSame($fixtureNamespace . 'A', $class_storage->custom_metadata['extends']);
        self::assertSame([$fixtureNamespace . 'I'], $class_storage->custom_metadata['implements']);
        self::assertSame('b', $class_storage->custom_metadata['a']);
        self::assertSame('d', $class_storage->methods['m']->custom_metadata['c']);
        self::assertSame('f', $class_storage->properties['prop']->custom_metadata['e']);
        self::assertSame('h', $class_storage->methods['m']->params[0]->custom_metadata['g']);
        self::assertSame('j', $file_storage->custom_metadata['i']);
    }

    /**
     * @test
     *
     */
    public function classExtendsRejectsUnpopulatedClasslikes(): void
    {
        $this->codebase->classlike_storage_provider->create('A');
        $this->codebase->classlike_storage_provider->create('B');

        $this->expectException(\Psalm\Exception\UnpopulatedClasslikeException::class);

        $this->codebase->classExtends('A', 'B');
    }
}
