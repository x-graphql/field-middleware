<?php

declare(strict_types=1);

namespace XGraphQL\FieldMiddleware;

use GraphQL\Executor\Executor;
use GraphQL\Executor\Promise\Promise;
use GraphQL\Type\Definition\AbstractType;
use GraphQL\Type\Definition\FieldDefinition;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Definition\WrappingType;
use GraphQL\Type\Schema;
use XGraphQL\FieldMiddleware\Exception\RuntimeException;

final class FieldMiddleware
{
    /**
     * @var \WeakMap<ObjectType>
     */
    private \WeakMap $appliedObjects;

    /**
     * @var MiddlewareInterface[]
     */
    private readonly iterable $middlewares;

    /**
     * @param MiddlewareInterface[] $middlewares
     */
    private function __construct(iterable $middlewares)
    {
        $this->appliedObjects = new \WeakMap();

        /// FIFO guaranteed
        $this->middlewares = array_reverse(iterator_to_array($middlewares));
    }

    private function applyObjectType(ObjectType $type): void
    {
        if (isset($this->appliedObjects[$type])) {
            return;
        }

        $middlewares = array_reverse(iterator_to_array($this->middlewares));

        foreach ($type->getFields() as $fieldDef) {
            /** @var FieldDefinition $fieldDef */
            $originalResolver = $fieldDef->resolveFn ?? $operationType->resolveFieldFn ?? Executor::getDefaultFieldResolver();

            $fieldDef->resolveFn = array_reduce($middlewares, $this->makeMiddlewareResolver(...), $originalResolver);
        }

        $originalResolver = $operationType->resolveFieldFn ?? Executor::defaultFieldResolver(...);

        $type->resolveFieldFn = array_reduce($middlewares, $this->makeMiddlewareResolver(...), $originalResolver);
    }

    private function prepareReturnType(Type $type): void
    {
        if ($type instanceof WrappingType) {
            $type->getInnermostType();
        }

        if ($type instanceof ObjectType) {
            $this->applyObjectType($type);
        }

        if ($type instanceof AbstractType) {
            $originalTypeResolver = $type->config['resolveType'] ?? null;

            $resolveType = fn($objectValue, $context, ResolveInfo $info) => $this->resolveAbstractType(
                $type,
                $originalTypeResolver,
                $objectValue,
                $context,
                $info,
            );

            $type->config['resolveType'] = $resolveType;
        }
    }

    private function resolveAbstractType(AbstractType $abstractType, ?callable $originalResolver, $objectValue, $context, ResolveInfo $info): ObjectType
    {
        $type = null !== $originalResolver ? $originalResolver($objectValue, $context, $info) : null;

        if (null === $type) {
            throw new RuntimeException(
                sprintf('Can not resolve abstract type: %s', $abstractType::class)
            );
        }

        if (is_string($type)) {
            $type = $info->schema->getType($type);
        }

        assert($type instanceof ObjectType);

        $this->applyObjectType($type);

        return $type;
    }

    private function makeMiddlewareResolver(\Closure $resolver, MiddlewareInterface $middleware): \Closure
    {
        return function ($value, $args, $context, ResolveInfo $info) use ($resolver, $middleware) {
            $resultOrPromise = $middleware->resolve($value, $args, $context, $info, $resolver);

            if ($resultOrPromise instanceof Promise) {
                return $resultOrPromise->then(
                    function (mixed $result) use ($info): mixed {
                        $this->prepareReturnType($info->returnType);

                        return $result;
                    }
                );
            }

            $this->prepareReturnType($info->returnType);

            return $resultOrPromise;
        };
    }

    /**
     * @param Schema $schema
     * @param MiddlewareInterface[] $middlewares
     * @return void
     */
    public static function apply(Schema $schema, iterable $middlewares): Schema
    {
        $instance = new self($middlewares);

        foreach (['query', 'mutation', 'subscription'] as $operation) {
            $operationType = $schema->getOperationType($operation);

            if (null === $operationType) {
                continue;
            }

            $instance->applyObjectType($operationType);
        }

        return $schema;
    }
}
