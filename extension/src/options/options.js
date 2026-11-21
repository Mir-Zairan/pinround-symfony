// The options page: connect with pinround's consent page or pair with a pasted token, or sign out (pairing.js), and
// the hosts scenarios may play on (allowed-host-list.js).
import { ApiError } from '../shared/api.js';
import { browser } from '../shared/browser.js';
import { ConnectRefused } from '../shared/oauth-connection.js';
import { SettingsError, loadSettings } from '../shared/settings.js';
import { AllowedHostList } from './allowed-host-list.js';
import { connectToPinround, pair, signOut } from './pairing.js';

const element = (id) => document.getElementById(id);

function showStatus(message, isError = false) {
    const status = element('status');
    status.textContent = message;
    status.classList.toggle('error', isError);
}

function showPaired(settings) {
    element('paired').hidden = null === settings;
    element('not-connected').hidden = null !== settings;
    element('paired-to').textContent = null === settings ? '' : `Connected to ${settings.origin}.`;
}

// Both forms' buttons stay disabled until the first one ends, so a second click opens no second sign-in window.
async function connectWith(connectOrPair, pendingMessage) {
    const submitButtons = document.querySelectorAll('#connect-form button[type="submit"], #pair-form button[type="submit"]');
    submitButtons.forEach((button) => { button.disabled = true; });
    showStatus(pendingMessage);
    try {
        showStatus(await connectOrPair());
        element('token').value = '';
        showPaired(await loadSettings());
    } catch (error) {
        showStatus(error instanceof SettingsError || error instanceof ApiError || error instanceof ConnectRefused ? error.message : 'Could not connect.', true);
    } finally {
        submitButtons.forEach((button) => { button.disabled = false; });
    }
}

element('connect-form').addEventListener('submit', (event) => {
    event.preventDefault();
    connectWith(() => connectToPinround(element('origin').value), 'Opening pinround…');
});
element('pair-form').addEventListener('submit', (event) => {
    event.preventDefault();
    connectWith(() => pair(element('origin').value, element('token').value), 'Checking the token…');
});
element('sign-out').addEventListener('click', async () => {
    showStatus(await signOut());
    showPaired(null);
});
loadSettings().then(showPaired);
new AllowedHostList(document, browser.storage.local).show();
