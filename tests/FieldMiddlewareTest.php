<?php

declare(strict_types=1);

namespace XGraphQL\FieldMiddleware\Test;

use GraphQL\Deferred;
use GraphQL\Error\DebugFlag;
use GraphQL\GraphQL;
use GraphQL\Type\Definition\InterfaceType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Schema;
use GraphQL\Utils\BuildSchema;
use PHPUnit\Framework\TestCase;
use XGraphQL\FieldMiddleware\Exception\RuntimeException;
use XGraphQL\FieldMiddleware\FieldMiddleware;
use XGraphQL\FieldMiddleware\MiddlewareInterface;

class FieldMiddlewareTest extends TestCase
{
    private const SDL = <<<'SDL'
type Query {
  person: Person
  getFruit: Fruit
}

interface Fruit {
  color: String!
}

type Apple implements Fruit {
  color: String!
}

type Banana implements Fruit {
  color: String!
}

type Person {
  children: [Person!]
  name: String!
}
SDL;

    public function testCanApply(): void
    {
        $schema = new Schema([
            'query' => new ObjectType([
                'name' => 'Query',
                'fields' => [
                    'dummy1' => [
                        'type' => Type::string(),
                        'resolve' => fn () => 'last',
                    ],
                    'dummy2' => Type::string(),
                ],
                'resolveField' => fn () => 'default last',
            ]),
            'mutation' => fn () => new ObjectType([
                'name' => 'Mutation',
                'fields' => [
                    'dummy3' => [
                        'type' => Type::string(),
                        'resolve' => fn () => 'last mutation',
                    ],
                ]
            ])
        ]);

        FieldMiddleware::apply($schema, [
            new class() implements MiddlewareInterface {
                public function resolve(
                    mixed $value,
                    array $arguments,
                    mixed $context,
                    ResolveInfo $info,
                    callable $next
                ): string {
                    return 'first - ' . $next($value, $arguments, $context, $info);
                }
            },
            new class() implements MiddlewareInterface {
                public function resolve(
                    mixed $value,
                    array $arguments,
                    mixed $context,
                    ResolveInfo $info,
                    callable $next
                ): string {
                    return 'second - ' . $next($value, $arguments, $context, $info);
                }
            }
        ]);

        $result = GraphQL::executeQuery($schema, '{ dummy1 dummy2 }');

        $this->assertEquals(
            [
                'data' => [
                    'dummy1' => 'first - second - last',
                    'dummy2' => 'first - second - default last',
                ]
            ],
            $result->toArray(DebugFlag::RETHROW_INTERNAL_EXCEPTIONS | DebugFlag::RETHROW_UNSAFE_EXCEPTIONS),
        );

        $result = GraphQL::executeQuery($schema, 'mutation { dummy3 }');

        $this->assertEquals(
            [
                'data' => [
                    'dummy3' => 'first - second - last mutation',
                ]
            ],
            $result->toArray(DebugFlag::RETHROW_INTERNAL_EXCEPTIONS | DebugFlag::RETHROW_UNSAFE_EXCEPTIONS),
        );
    }

    public function testNotApplyMiddlewareTwice(): void
    {
        $schema = BuildSchema::build(self::SDL);

        FieldMiddleware::apply($schema, [
            new class() implements MiddlewareInterface {
                public function resolve(
                    mixed $value,
                    array $arguments,
                    mixed $context,
                    ResolveInfo $info,
                    callable $next
                ): mixed {
                    if ($info->fieldName === 'name') {
                        return $next($value, $arguments, $context, $info) . ' Doe';
                    }

                    return $next($value, $arguments, $context, $info);
                }
            }
        ]);

        $query = <<<'GQL'
query {
  person {
    name
    children {
      name
      children {
        name
      }
    }
  }
}
GQL;
        $result = GraphQL::executeQuery(
            $schema,
            $query,
            [
                'person' => [
                    'name' => 'Jane',
                    'children' => [
                        [
                            'name' => 'John',
                            'children' => [
                                [
                                    'name' => 'Zoe'
                                ]
                            ]
                        ],
                    ],
                ],
            ],
        );

        $this->assertSame(
            [
                'data' => [
                    'person' => [
                        'name' => 'Jane Doe',
                        'children' => [
                            [
                                'name' => 'John Doe',
                                'children' => [
                                    ['name' => 'Zoe Doe']
                                ]
                            ],
                        ],
                    ],
                ]
            ],
            $result->toArray(DebugFlag::RETHROW_INTERNAL_EXCEPTIONS | DebugFlag::RETHROW_UNSAFE_EXCEPTIONS)
        );
    }

    public function testApplyToThenable(): void
    {
        $schema = new Schema([
            'query' => new ObjectType([
                'name' => 'Query',
                'fields' => [
                    'person' => [
                        'type' => new ObjectType([
                            'name' => 'Person',
                            'fields' => [
                                'name' => Type::string()
                            ]
                        ]),
                        'resolve' => static function ($value, $args, $context, ResolveInfo $info) {
                            return new Deferred(function () use ($info) {
                                $returnType = $info->returnType;
                                assert($returnType instanceof ObjectType);
                                $returnType->resolveFieldFn = fn () => 'John';

                                return [];
                            });
                        }
                    ],
                ],
            ])
        ]);

        FieldMiddleware::apply(
            $schema,
            [
                new class() implements MiddlewareInterface {
                    public function resolve(
                        mixed $value,
                        array $arguments,
                        mixed $context,
                        ResolveInfo $info,
                        callable $next
                    ): mixed {
                        $result = $next($value, $arguments, $context, $info);

                        if ($info->fieldName === 'name') {
                            return $result . ' Doe';
                        }

                        return $result;
                    }
                }
            ]
        );

        $result = GraphQL::executeQuery($schema, '{ person { name } }');

        $this->assertEquals(
            [
                'data' => [
                    'person' => [
                        'name' => 'John Doe'
                    ]
                ]
            ],
            $result->toArray()
        );
    }

    public function testApplyAbstractType(): void
    {
        $schema = BuildSchema::build(self::SDL);

        FieldMiddleware::apply($schema, [
            new class() implements MiddlewareInterface {
                public function resolve(
                    mixed $value,
                    array $arguments,
                    mixed $context,
                    ResolveInfo $info,
                    callable $next
                ): mixed {
                    $returnType = $info->returnType;

                    if ($returnType instanceof InterfaceType && $info->fieldName === 'getFruit') {
                        $returnType->config['resolveType'] = function ($value, $args, ResolveInfo $info) {
                            if ($value['color'] === 'red') {
                                return $info->schema->getType('Apple');
                            }

                            return 'Banana';
                        };

                        $path = end($info->path);

                        return $value[$path];
                    }

                    return $next($value, $arguments, $context, $info);
                }
            }
        ]);

        $query = <<<'GQL'
query {
    apple: getFruit {
        __typename
    }
    banana: getFruit {
        __typename
    }
}
GQL;

        $result = GraphQL::executeQuery(
            $schema,
            $query,
            [
                'apple' => [
                    'color' => 'red'
                ],
                'banana' => [
                    'color' => 'yellow'
                ]
            ]
        );

        $this->assertEquals(
            [
                'data' => [
                    'apple' => [
                        '__typename' => 'Apple'
                    ],
                    'banana' => [
                        '__typename' => 'Banana'
                    ]
                ],
            ],
            $result->toArray(DebugFlag::RETHROW_INTERNAL_EXCEPTIONS | DebugFlag::RETHROW_UNSAFE_EXCEPTIONS),
        );
    }

    public function testApplyAbstractTypeNotHaveTypeResolverShouldThrowException(): void
    {
        $schema = BuildSchema::build(self::SDL);

        FieldMiddleware::apply($schema, [
            new class() implements MiddlewareInterface {
                public function resolve(
                    mixed $value,
                    array $arguments,
                    mixed $context,
                    ResolveInfo $info,
                    callable $next
                ): mixed {
                    return $value;
                }
            }
        ]);

        $result = GraphQL::executeQuery(
            $schema,
            'query { getFruit { color } } ',
            [
                'getFruit' => [
                    'color' => 'red'
                ]
            ]
        );

        $this->expectException(RuntimeException::class);

        $result->toArray(DebugFlag::RETHROW_INTERNAL_EXCEPTIONS);
    }
}
