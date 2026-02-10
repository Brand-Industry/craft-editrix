import { createApp } from 'vue';
import App from './App.vue';
import '@/scss/editrix.scss';

const mountEl = document.getElementById('editrix-app');

if (mountEl) {
  const app = createApp(App);
  app.mount(mountEl);
}
