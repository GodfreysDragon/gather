<?php

namespace App\GraphQL;

use GraphQL\Validator\Rules\QueryDepth;
use Nuwave\Lighthouse\Execution\CacheableValidationRulesProvider;

/**
 * Lighthouse's validation rules, with the depth limit swapped for OperationDepth.
 */
class ValidationRules extends CacheableValidationRulesProvider
{
    public function cacheableValidationRules(): array
    {
        return [
            ...parent::cacheableValidationRules(),
            QueryDepth::class => new OperationDepth($this->configRepository->get('lighthouse.security.max_query_depth', 0)),
        ];
    }
}
