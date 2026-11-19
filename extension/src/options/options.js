// The options page: pair with a pasted token, or sign out (pairing.js).
import { ApiError } from '../shared/api.js';
import { SettingsError, loadSettings } from '../shared/settings.js';
import { pair, signOut } from './pairing.js';

const element = (id) => document.getElementById(id);

function showStatus(message, isError = false) {
    const status = element('status');
    status.textContent = message;
    status.classList.toggle('error', isError);
}

function showPaired(settings) {
    element('paired').hidden = null === settings;
    element('pair-form').hidden = null !== settings;
    element('paired-to').textContent = null === settings ? '' : `Connected to ${settings.origin}.`;
}

element('pair-form').addEventListener('submit', async (event) => {
    event.preventDefault();
    try {
        showStatus(await pair(element('origin').value, element('token').value));
        element('token').value = '';
        showPaired(await loadSettings());
    } catch (error) {
        showStatus(error instanceof SettingsError || error instanceof ApiError ? error.message : 'Could not connect.', true);
    }
});
element('sign-out').addEventListener('click', async () => {
    showStatus(await signOut());
    showPaired(null);
});
loadSettings().then(showPaired);
