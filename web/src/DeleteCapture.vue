<script setup>
import {ref} from 'vue'
import {api,errorMessage} from './api'
const props=defineProps({item:{type:Object,required:true}}),emit=defineEmits(['deleted'])
const show=ref(false),reason=ref(''),busy=ref(false),error=ref('')
async function remove(){busy.value=true;error.value='';try{const {data}=await api.post('/local/my-screenshot/delete',{id:props.item.id,reason:reason.value});show.value=false;emit('deleted',data.message)}catch(e){error.value=errorMessage(e)}finally{busy.value=false}}
</script>
<template><button v-if="!item.deleted" @click.stop="show=true">Delete screenshot</button><Teleport to="body"><div v-if="show" class="modal-backdrop" @click.self="!busy && (show=false)"><form class="card" role="dialog" aria-modal="true" aria-labelledby="delete-title" style="width:min(520px,90vw)" @submit.prevent="remove"><h2 id="delete-title">Delete this screenshot?</h2><p>The original and thumbnail will be removed from active server storage. A shared placeholder and deletion record remain. This does not change your score or create a flag.</p><p>Previously downloaded copies or backups cannot be recalled by this action.</p><label>Reason (optional)<textarea v-model.trim="reason" maxlength="1000" rows="3"></textarea></label><p v-if="error" class="notice danger" role="alert">{{error}}</p><div class="inline-actions"><button type="button" :disabled="busy" @click="show=false">Cancel</button><button class="primary" :disabled="busy">{{busy?'Deleting…':'Confirm deletion'}}</button></div></form></div></Teleport></template>
