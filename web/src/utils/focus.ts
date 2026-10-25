import { nextTick } from 'vue'

/** After a failed submit, move focus to the first field marked invalid, once it has rendered. */
export async function focusFirstInvalid(form: HTMLElement | null): Promise<void> {
  await nextTick()
  form?.querySelector<HTMLElement>('[aria-invalid="true"]')?.focus()
}
