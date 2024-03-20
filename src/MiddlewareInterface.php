<?php

declare(strict_types=1);

namespace XGraphQL\FieldMiddleware;

use GraphQL\Type\Definition\ResolveInfo;

interface MiddlewareInterface
{
    public function resolve(mixed $value, array $arguments, mixed $context, ResolveInfo $info, callable $next): mixed;
}
