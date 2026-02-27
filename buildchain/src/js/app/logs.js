import { createApp } from 'vue';
import LogsApp from './LogsApp.vue';
import '@/scss/logs.scss';

const mountEl = document.getElementById('editrix-logs-app');

if (mountEl) {
  const app = createApp(LogsApp);
  app.mount(mountEl);
}
