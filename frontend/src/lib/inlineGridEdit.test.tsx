/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

import { createRoot } from 'solid-js'
import { describe, expect, it, vi } from 'vitest'

import type { FormValues } from '../admin/types'
import { createInlineGridEdit, type InlineGridEditConfig } from './inlineGridEdit'

interface Row {
  id: number
  name: string
}

/** The controller needs a reactive owner; tests run it inside a throwaway root. */
function withController<T>(
  overrides: Partial<InlineGridEditConfig<Row>>,
  body: (edit: ReturnType<typeof createInlineGridEdit<Row>>) => Promise<T>,
): Promise<T> {
  return createRoot(async (dispose) => {
    try {
      const edit = createInlineGridEdit<Row>({
        rows: () => [{ id: 1, name: 'a' }],
        fieldFor: () => ({ key: 'name', type: 'text' }) as never,
        isInlineEditable: () => true,
        seedDraft: (row) => ({ name: row.name }),
        saveRow: () => Promise.resolve(),
        ...overrides,
      })

      return await body(edit)
    } finally {
      dispose()
    }
  })
}

describe('createInlineGridEdit flushRow', () => {
  it('saves the changed draft, then drops it and its hints and reports the save', async () => {
    const saveRow = vi.fn(() => Promise.resolve())
    const onSaved = vi.fn()
    await withController({ saveRow, onSaved }, async (edit) => {
      edit.beginEdit(1, 'name')
      edit.setDraftField(1, 'name', 'b')
      await edit.flushRow(1)

      expect(saveRow).toHaveBeenCalledWith({ name: 'b' }, { id: 1, name: 'a' })
      expect(onSaved).toHaveBeenCalledTimes(1)
      expect(edit.drafts[1]).toBeUndefined()
      expect(edit.fieldHints[1]).toBeUndefined()
      expect(edit.savingRows[1]).toBe(false)
      expect(edit.rowErrors[1]).toBe('')
    })
  })

  it('keeps the draft when it changed while the save was in flight, and re-derives its hints', async () => {
    let release: () => void = () => undefined
    const saveRow = vi.fn(() => new Promise<void>((resolve) => { release = resolve }))
    const onSaved = vi.fn()
    const invalidFields = vi.fn((draft: FormValues) => (draft.name === 'c' ? ['name'] : []))
    await withController({ saveRow, onSaved, invalidFields }, async (edit) => {
      edit.beginEdit(1, 'name')
      edit.setDraftField(1, 'name', 'b')
      const flushing = edit.flushRow(1)
      edit.setDraftField(1, 'name', 'c') // edited while the POST is out
      release()
      await flushing

      expect(onSaved).toHaveBeenCalledTimes(1)
      expect(edit.drafts[1]).toEqual({ name: 'c' }) // newer edit survives
      expect(edit.fieldHints[1]).toEqual(['name']) // hints recomputed for it
    })
  })

  it('surfaces a rejected save as a row error and keeps the draft without reporting a save', async () => {
    const saveRow = vi.fn(() => Promise.reject(new Error('boom')))
    const onSaved = vi.fn()
    await withController({ saveRow, onSaved, saveErrorMessage: (e) => `failed: ${(e as Error).message}` }, async (edit) => {
      edit.beginEdit(1, 'name')
      edit.setDraftField(1, 'name', 'b')
      await edit.flushRow(1)

      expect(onSaved).not.toHaveBeenCalled()
      expect(edit.rowErrors[1]).toBe('failed: boom')
      expect(edit.drafts[1]).toEqual({ name: 'b' })
      expect(edit.savingRows[1]).toBe(false)
    })
  })

  it('parks a half-filled NEW row with field hints instead of posting it', async () => {
    const saveRow = vi.fn(() => Promise.resolve())
    await withController({
      saveRow,
      isNewRow: () => true,
      invalidFields: (draft) => (draft.name === '' ? ['name'] : []),
    }, async (edit) => {
      edit.beginEdit(1, 'name')
      edit.setDraftField(1, 'name', '')
      await edit.flushRow(1)

      expect(saveRow).not.toHaveBeenCalled()
      expect(edit.fieldHints[1]).toEqual(['name'])
      expect(edit.drafts[1]).toEqual({ name: '' })
      expect(edit.rowErrors[1]).toBe('')
    })
  })

  it('does not park an EXISTING row that misses a field (the server decides), and saves a complete new row', async () => {
    const saveRow = vi.fn(() => Promise.resolve())
    await withController({
      saveRow,
      isNewRow: () => false,
      invalidFields: () => ['name'],
    }, async (edit) => {
      edit.beginEdit(1, 'name')
      edit.setDraftField(1, 'name', 'b')
      await edit.flushRow(1)
      expect(saveRow).toHaveBeenCalledTimes(1)
    })

    const saveNew = vi.fn(() => Promise.resolve())
    await withController({
      saveRow: saveNew,
      isNewRow: () => true,
      invalidFields: () => [],
    }, async (edit) => {
      edit.beginEdit(1, 'name') // untouched draft == seed, but a complete NEW row still saves (#495)
      await edit.flushRow(1)
      expect(saveNew).toHaveBeenCalledTimes(1)
    })
  })

  it('drops an untouched draft of an existing row without posting', async () => {
    const saveRow = vi.fn(() => Promise.resolve())
    await withController({ saveRow }, async (edit) => {
      edit.beginEdit(1, 'name')
      await edit.flushRow(1)

      expect(saveRow).not.toHaveBeenCalled()
      expect(edit.drafts[1]).toBeUndefined()
    })
  })
})
