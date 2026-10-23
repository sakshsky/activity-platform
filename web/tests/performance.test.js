import test from 'node:test'
import assert from 'node:assert/strict'
import {reviewedScore} from '../src/performance-score.js'
test('review preview matches server weighted score fixtures',()=>{assert.equal(reviewedScore({completion:100,quality:90,deadlines:100},60),93);assert.equal(reviewedScore({completion:75,quality:100,deadlines:25},80),73);assert.equal(reviewedScore({completion:66.67,quality:85,deadlines:66.67},80),73.5)})
