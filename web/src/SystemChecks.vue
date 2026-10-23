<script setup>
import { onMounted, ref } from 'vue'
import { api, errorMessage, localTime } from './api'
const props = defineProps({ agent: Boolean })
const report = ref(null), busy = ref(false), error = ref('')
const labels = {pass:'Passed',fail:'Needs attention',warning:'Check required',manual:'Manual check'}
async function run(){if(busy.value)return;busy.value=true;error.value='';try{report.value=(await api.post(props.agent?'/local/system-check':'/api/admin/system-check',{})).data}catch(e){error.value=errorMessage(e)}finally{busy.value=false}}
onMounted(run)
</script>
<template>
<section class="card system-checks">
 <div class="card-heading"><div><h2>System requirements & checks</h2><p>{{agent?'This workstation':'Laravel server'}} · {{report?localTime(report.checked_at):'Not checked yet'}}</p></div><button :disabled="busy" @click="run">{{busy?'Checking…':'Run checks again'}}</button></div>
 <p>Checks report current readiness. They do not start screen capture, enable recording, send employee data, or change your policy.</p>
 <p v-if="error" class="notice danger" role="alert">{{error}}</p><p v-if="busy" role="status">Testing requirements…</p>
 <div v-if="report" class="check-list"><article v-for="check in report.checks" :key="check.name"><div><h3>{{check.name}}</h3><span class="pill" :class="check.status==='pass'?'green':'neutral'">{{labels[check.status] || check.status}}</span></div><p>{{check.detail}}</p></article></div>
 <p class="muted">A passed check confirms only the test described. Items marked Manual check still need verification. Real recording requires an employee session, notice acknowledgment, active input and a recent server connection.</p>
</section>
</template>
<style scoped>
.system-checks{max-width:1100px}.check-list{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,300px),1fr));gap:14px;margin:20px 0}.check-list article{border:1px solid #dce5e4;border-radius:12px;padding:16px;min-width:0}.check-list article>div{display:flex;gap:12px;align-items:center;justify-content:space-between;flex-wrap:wrap}.check-list h3{margin:0;font-size:15px}.check-list p{overflow-wrap:anywhere;font-size:14px}.system-checks>.card-heading{flex-wrap:wrap;gap:12px}
</style>
