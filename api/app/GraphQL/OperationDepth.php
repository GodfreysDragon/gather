<?php

namespace App\GraphQL;

use GraphQL\Language\AST\FieldNode;
use GraphQL\Language\AST\Node;
use GraphQL\Validator\Rules\QueryDepth;

/**
 * The query depth limit, with a separate, higher cap for introspection (`__schema`,
 * `__type`). The standard introspection query that GraphiQL and code generators send
 * is deeper than any data query needs, but introspection types are cyclic
 * (`ofType { fields { type { ofType … } } }`), so it still needs a limit.
 */
class OperationDepth extends QueryDepth
{
    public const INTROSPECTION_MAX_DEPTH = 15;

    protected function nodeDepth(Node $node, int $depth = 0, int $maxDepth = 0): int
    {
        if ($node instanceof FieldNode && in_array($node->name->value, ['__schema', '__type'], true)) {
            $introspectionDepth = parent::nodeDepth($node);

            // Within the introspection cap it doesn't count towards the data limit; past it,
            // it counts in full, which takes the operation over that limit too.
            return $introspectionDepth <= self::INTROSPECTION_MAX_DEPTH
                ? $maxDepth
                : max($maxDepth, $depth + $introspectionDepth);
        }

        return parent::nodeDepth($node, $depth, $maxDepth);
    }
}
