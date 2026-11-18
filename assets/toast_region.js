// The empty toast _toast.html.twig renders for a flash: the toast controller writes the text in after connect, so the
// region's role="status" announces it. It replaces any toast already there, as the region shows one at a time. No
// message, or a page without the region, shows nothing.
export function showToast(message) {
  const region = document.getElementById('toast-region');
  if (!message || region === null) {
    return;
  }
  const toast = document.createElement('div');
  toast.className = 'toast';
  toast.dataset.controller = 'toast';
  toast.dataset.toastMessageValue = message;
  toast.dataset.turboTemporary = '';
  region.replaceChildren(toast);
}
