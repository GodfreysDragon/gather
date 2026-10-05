/// <reference types="vite/client" />

interface ImportMetaEnv {
  /** Origin of the Gather API, e.g. http://localhost:8000. The SPA is deployed separately. */
  readonly VITE_API_URL: string
}

interface ImportMeta {
  readonly env: ImportMetaEnv
}
