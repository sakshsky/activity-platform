import { createApp } from 'vue'
import AdminApp from './AdminApp.vue'
import AgentApp from './AgentApp.vue'
import './style.css'

createApp(location.pathname.startsWith('/agent') ? AgentApp : AdminApp).mount('#app')
