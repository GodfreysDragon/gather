<?php

namespace App\GraphQL;

use GraphQL\Language\AST\FieldNode;
use GraphQL\Language\AST\Node;
use GraphQL\Validator\Rules\QueryDepth;

/**
 * The query depth limit, applied to data fields only. Introspection (`__schema`,
 * `__type`) is bounded by the schema itself, and the standard introspection query
 * that GraphiQL and code generators send is deeper than any real data query needs.
 */
class OperationDepth extends QueryDepth
{
    protected function nodeDepth(Node $node, int $depth = 0, int $maxDepth = 0): int
    {
        if ($node instanceof FieldNode && in_array($node->name->value, ['__schema', '__type'], true)) {
            return $maxDepth;
        }

        return parent::nodeDepth($node, $depth, $maxDepth);
    }
}
