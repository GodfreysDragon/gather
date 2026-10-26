import type { CodegenConfig } from '@graphql-codegen/cli'

/**
 * Generates TypeScript types for every GraphQL operation in the SPA from the API's
 * compiled schema (../schema.graphql, exported by `composer schema:graphql` in api/).
 * Codegen also validates each operation against the schema, so a removed or renamed
 * field fails here, and a changed type fails `npm run type-check`.
 */
const config: CodegenConfig = {
  schema: '../schema.graphql',
  documents: ['src/**/*.ts', '!src/**/*.generated.ts', '!src/__tests__/**'],
  generates: {
    'src/api/graphql.generated.ts': {
      plugins: ['typescript-operations'],
      config: {
        enumsAsTypes: true,
        inlineFragmentTypes: 'combine',
        avoidOptionals: { field: true, inputValue: false, object: true },
        scalars: { DateTime: 'string', ID: { input: 'string | number', output: 'string' } },
        useTypeImports: true,
      },
    },
  },
  hooks: { afterAllFileWrite: ['prettier --write'] },
}

export default config
