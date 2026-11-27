/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { createApp } from 'vue';
import { createAlohaUi } from '@alohasocial/ui';
import '@alohasocial/ui/style.css';
import './style.css';
import './brand.css';
import './modern.css';
import './admin.css';
import './design.css';
import App from './App.vue';
import { bridge } from './bridge';

createApp(App).use(createAlohaUi({ bridge })).mount('#aloha-social');
