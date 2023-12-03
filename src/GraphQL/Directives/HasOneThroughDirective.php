<?php declare(strict_types=1);

namespace Lunargraphql\GraphQL\Directives;

use Illuminate\Database\Eloquent\Model;
use Nuwave\Lighthouse\Execution\ResolveInfo;
use Nuwave\Lighthouse\Schema\Directives\RelationDirective;
use Nuwave\Lighthouse\Schema\Values\FieldValue;
use Nuwave\Lighthouse\Support\Contracts\FieldManipulator;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class HasOneThroughDirective extends RelationDirective implements FieldManipulator
{
    public static function definition(): string
    {
        return /** @lang GraphQL */ <<<'GRAPHQL'
"""
Corresponds to [the Eloquent relationship HasOneThrough](https://laravel.com/docs/eloquent-relationships#has-one-through).
"""
directive @hasOneThrough(
  """
  Specify the relationship method name in the model class,
  if it is named different from the field in the schema.
  """
  relation: String

  """
  Apply scopes to the underlying query.
  """
  scopes: [String!]
) on FIELD_DEFINITION
GRAPHQL;
    }

    public function resolveField(FieldValue $fieldValue): callable
    {
        $relationName = $this->relation();

        return function (Model $parent, array $args, GraphQLContext $context, ResolveInfo $resolveInfo) use ($relationName) {

            $relation = $this->directiveArgValue('relation', $relationName);
            $relation = $parent->{$relation}();

            if ($scopes = $this->directiveArgValue('scopes')) {
                foreach ($scopes as $scope) {
                    $relation->{$scope}();
                }
            }

            return $relation->getResults();
        };
    }
}
