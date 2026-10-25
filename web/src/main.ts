import './assets/main.css'

import { createApp } from 'vue'
import { createPinia } from 'pinia'

import App from './App.vue'
import router from './router'
import { redirectOnUnauthenticated } from './router/sessionExpiry'

const app = createApp(App)

app.use(createPinia())
app.use(router)
redirectOnUnauthenticated(router)

app.mount('#app')
