import test from 'node:test'
import assert from 'node:assert/strict'
import { filterParams, errorMessage } from '../src/api.js'

test('empty filters do not become misleading API values', () => {
  assert.deepEqual(filterParams({ user_id: '', app: 'editor.exe', from: null }), { app: 'editor.exe' })
})
test('validation errors are shown to the user', () => {
  assert.equal(errorMessage({ response: { data: { errors: { email: ['Invalid account.'], password: ['Password required.'] } } } }), 'Invalid account. Password required.')
})
