// The Go loopback server owns /agent; Laravel serves the admin entry at /.
import { createApp } from 'vue'
import AdminApp from './AdminApp.vue'
import AgentApp from './AgentApp.vue'
import './style.css'

createApp(location.pathname.startsWith('/agent') ? AgentApp : AdminApp).mount('#app')
