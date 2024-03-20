<?php

declare(strict_types=1);

namespace XGraphQL\FieldMiddleware;

use GraphQL\Executor\Executor;
use GraphQL\Executor\Promise\Promise;
use GraphQL\Executor\Promise\PromiseAdapter;
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
    private function __construct(iterable $middlewares, private PromiseAdapter $promiseAdapter)
    {
        $this->appliedObjects = new \WeakMap();

        /// FIFO guaranteed
        $this->middlewares = array_reverse(iterator_to_array($middlewares));
    }

    /**
     * @param Schema $schema
     * @param MiddlewareInterface[] $middlewares
     * @return Schema
     */
    public static function apply(Schema $schema, iterable $middlewares, PromiseAdapter $promiseAdapter = null): Schema
    {
        $promiseAdapter ??= Executor::getPromiseAdapter();
        $instance = new self($middlewares, $promiseAdapter);

        foreach (['query', 'mutation', 'subscription'] as $operation) {
            $operationType = $schema->getOperationType($operation);

            if (null === $operationType) {
                continue;
            }

            $instance->applyObjectType($operationType);
        }

        return $schema;
    }

    private function applyObjectType(ObjectType $type): void
    {
        if (isset($this->appliedObjects[$type])) {
            return;
        }

        foreach ($type->getFields() as $fieldDef) {
            /** @var FieldDefinition $fieldDef */
            $originalResolver = $fieldDef->resolveFn ?? $type->resolveFieldFn ?? Executor::getDefaultFieldResolver();

            $fieldDef->resolveFn = $this->makeResolver($originalResolver(...));
        }

        $originalResolver = $type->resolveFieldFn ?? Executor::getDefaultFieldResolver();

        $type->resolveFieldFn = $this->makeResolver($originalResolver(...));

        $this->appliedObjects[$type] = true;
    }

    private function makeResolver(\Closure $originalResolver): \Closure
    {
        $middlewareResolver = array_reduce(
            $this->middlewares,
            $this->makeMiddlewareResolver(...),
            $originalResolver
        );

        return function ($value, $args, $context, ResolveInfo $info) use ($middlewareResolver): mixed {
            $result = $middlewareResolver($value, $args, $context, $info);

            if ($this->promiseAdapter->isThenable($result)) {
                $result = $this->promiseAdapter->convertThenable($result);
            }

            if ($result instanceof Promise) {
                return $result->then(
                    function (mixed $result) use ($info): mixed {
                        $this->prepareReturnType($info->returnType);

                        return $result;
                    }
                );
            }

            $this->prepareReturnType($info->returnType);

            return $result;
        };
    }

    private function makeMiddlewareResolver(\Closure $resolver, MiddlewareInterface $middleware): \Closure
    {
        return function ($value, $args, $context, $info) use ($resolver, $middleware): mixed {
            return $middleware->resolve($value, $args, $context, $info, $resolver);
        };
    }

    private function prepareReturnType(Type $type): void
    {
        if ($type instanceof WrappingType) {
            $type = $type->getInnermostType();
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
}
