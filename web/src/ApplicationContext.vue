<script setup>
import {duration} from './employee-utils'
defineProps({context:Object})
</script>
<template><section v-if="context" class="card"><h2>Application time · estimated</h2><p class="muted">{{context.description}}</p><div class="table-scroll"><table><thead><tr><th>Date</th><th>Work related</th><th>Neutral</th><th>Review context</th><th>Unclassified</th></tr></thead><tbody><tr v-for="d in context.daily" :key="d.date"><td>{{d.date}}</td><td>{{duration(d.work_related)}}</td><td>{{duration(d.neutral)}}</td><td>{{duration(d.review_context)}}</td><td>{{duration(d.unclassified)}}</td></tr></tbody></table></div><p class="small muted">{{context.legacy_samples}} older samples have no duration. {{context.observed_app_switches}} foreground changes observed between samples; changes within a sampling interval may be missed.</p><p v-if="context.review_context" class="notice">{{context.review_context}}</p></section></template>
