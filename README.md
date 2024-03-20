Field Middleware
================

Adding custom logics before and after when resolving fields

![unit tests](https://github.com/x-graphql/field-middleware/actions/workflows/unit_tests.yml/badge.svg)
[![codecov](https://codecov.io/gh/x-graphql/field-middleware/graph/badge.svg?token=ntJX4QUcVk)](https://codecov.io/gh/x-graphql/field-middleware)

Getting Started
---------------

Install this package via [Composer](https://getcomposer.org)

```shell
composer require x-graphql/field-middleware
```

Usages
------

Create your first middleware:

```php
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Schema;
use XGraphQL\FieldMiddleware\MiddlewareInterface;

class MyMiddleware implements MiddlewareInterface {

    public function resolve(mixed $value, array $arguments, mixed $context, ResolveInfo $info, callable $next) : mixed {
        $firstName = $next($value, $arguments, $context, $info);
        
        return $firstName . ' Doe';
    }
}
```

Then let apply this middleware to schema:

```php
use GraphQL\GraphQL;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Schema;
use XGraphQL\FieldMiddleware\FieldMiddleware;

$schema = new Schema([
  'query' => new ObjectType([
    'name' => 'Query',
    'fields' => [
      'name' => Type::string()
    ],
  ]),
]);

FieldMiddleware::apply($schema, [new MyMiddleware()]);

$result = GraphQL::executeQuery($schema, '{ name }', ['name' => 'John']);

var_dump($result->toArray());
```
Credits
-------

Created by [Minh Vuong](https://github.com/vuongxuongminh)
