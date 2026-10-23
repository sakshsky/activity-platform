import test from 'node:test'
import assert from 'node:assert/strict'
import { duration } from '../src/employee-utils.js'
test('time display preserves totals longer than one day without wrapping',()=>{
  assert.equal(duration(60*60*49+60*8),'49h 08m')
  assert.equal(duration(59),'0h 00m')
  assert.equal(duration(-1),'0h 00m')
})
