import { createServer } from 'node:http';
import {
  DISCOUNT_CODES,
  ORDER_STATUSES,
  cartSummary,
  filterOrders,
  findProduct,
  formatEuro,
  searchProducts,
  seedOrders,
} from './src/shop.js';

const PORT = Number(process.env.PORT ?? 8090);
const MAX_BODY_BYTES = 10_000;
const MAX_QUANTITY = 99;

// One shopper per server: the demo is run by one person on their own machine.
let state = freshState();

function freshState() {
  return { cart: [], discountCode: null, orders: seedOrders(), flash: null };
}

function escapeHtml(text) {
  return String(text)
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#39;');
}

function takeFlash() {
  const flash = state.flash;
  state.flash = null;
  return flash === null ? '' : `<p class="flash" role="status">${escapeHtml(flash)}</p>`;
}

function cartCount() {
  return state.cart.reduce((count, line) => count + line.quantity, 0);
}

function page(title, body) {
  return `<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>${escapeHtml(title)} · Acme Foods shop</title>
<style>
  :root { --ink:#1d2433; --muted:#5b6475; --line:#d9dee7; --accent:#2f6fde; --bg:#f6f7fb; --ok:#1f7a4d; }
  * { box-sizing: border-box; }
  body { margin:0; font:16px/1.5 system-ui, sans-serif; color:var(--ink); background:var(--bg); }
  header { display:flex; flex-wrap:wrap; gap:16px; align-items:center; padding:12px 20px; background:#fff; border-bottom:1px solid var(--line); }
  header strong { margin-right:auto; }
  nav a { margin-right:14px; color:var(--accent); text-decoration:none; }
  main { max-width:820px; margin:24px auto; padding:0 16px; }
  table { width:100%; border-collapse:collapse; background:#fff; }
  th, td { text-align:left; padding:8px 10px; border-bottom:1px solid var(--line); }
  form.inline { display:inline; }
  button { font:inherit; padding:6px 12px; border:1px solid var(--accent); background:var(--accent); color:#fff; border-radius:6px; cursor:pointer; }
  button.quiet { background:#fff; color:var(--accent); }
  input, select { font:inherit; padding:6px 8px; border:1px solid var(--line); border-radius:6px; }
  label { display:block; margin:10px 0 4px; }
  .flash { padding:10px 12px; background:#e7f5ee; color:var(--ok); border-radius:6px; }
  .error { padding:10px 12px; background:#fdecec; color:#a52a2a; border-radius:6px; }
  .total { font-size:20px; font-weight:600; }
  .muted { color:var(--muted); }
</style>
</head>
<body>
<header>
  <strong>Acme Foods shop</strong>
  <nav aria-label="Main">
    <a href="/products">Products</a>
    <a href="/cart">Cart (${cartCount()})</a>
    <a href="/checkout">Checkout</a>
    <a href="/orders">Orders</a>
  </nav>
  <form class="inline" method="post" action="/reset"><button class="quiet" type="submit">Reset demo data</button></form>
</header>
<main>
<h1>${escapeHtml(title)}</h1>
${takeFlash()}
${body}
</main>
</body>
</html>`;
}

function productsPage(url) {
  const query = url.searchParams.get('q') ?? '';
  const found = searchProducts(query);
  const rows = found
    .map(
      (product) => `<tr>
  <td>${escapeHtml(product.name)}</td>
  <td>${formatEuro(product.priceCents)}</td>
  <td><form class="inline" method="post" action="/cart/add">
    <input type="hidden" name="product" value="${escapeHtml(product.id)}">
    <button type="submit" aria-label="Add ${escapeHtml(product.name)} to cart">Add to cart</button>
  </form></td>
</tr>`,
    )
    .join('');
  const list =
    found.length === 0
      ? `<p class="muted">No products match “${escapeHtml(query)}”.</p>`
      : `<table><thead><tr><th>Product</th><th>Price</th><th></th></tr></thead><tbody>${rows}</tbody></table>`;
  return page(
    'Products',
    `<form method="get" action="/products" role="search">
  <label for="q">Search products</label>
  <input id="q" name="q" value="${escapeHtml(query)}">
  <button type="submit">Search</button>
</form>
<p></p>
${list}`,
  );
}

function cartPage(error = '') {
  if (state.cart.length === 0) {
    return page('Cart', `<p class="muted">Your cart is empty.</p><p><a href="/products">Go to products</a></p>`);
  }
  const summary = cartSummary(state.cart, state.discountCode);
  const rows = state.cart
    .map((line) => {
      const product = findProduct(line.productId);
      return `<tr>
  <td>${escapeHtml(product.name)}</td>
  <td>${formatEuro(product.priceCents)}</td>
  <td>
    <form class="inline" method="post" action="/cart/quantity">
      <input type="hidden" name="product" value="${escapeHtml(product.id)}">
      <input type="hidden" name="change" value="-1">
      <button class="quiet" type="submit" aria-label="One less ${escapeHtml(product.name)}">−</button>
    </form>
    <span aria-label="Quantity of ${escapeHtml(product.name)}">${line.quantity}</span>
    <form class="inline" method="post" action="/cart/quantity">
      <input type="hidden" name="product" value="${escapeHtml(product.id)}">
      <input type="hidden" name="change" value="1">
      <button class="quiet" type="submit" aria-label="One more ${escapeHtml(product.name)}">+</button>
    </form>
  </td>
</tr>`;
    })
    .join('');
  const discountLine =
    summary.discountCents > 0
      ? `<p>Discount (${escapeHtml(state.discountCode)}): −${formatEuro(summary.discountCents)}</p>`
      : '';
  const totalLine = summary.showTotal ? `<p class="total" data-testid="cart-total">Total: ${formatEuro(summary.totalCents)}</p>` : '';
  return page(
    'Cart',
    `<table><thead><tr><th>Product</th><th>Price</th><th>Quantity</th></tr></thead><tbody>${rows}</tbody></table>
<p>Subtotal: ${formatEuro(summary.subtotalCents)}</p>
${discountLine}
${totalLine}
${error === '' ? '' : `<p class="error" role="alert">${escapeHtml(error)}</p>`}
<form method="post" action="/cart/discount">
  <label for="code">Discount code</label>
  <input id="code" name="code" maxlength="20">
  <button type="submit">Apply</button>
</form>
<p></p>
<p><a href="/checkout">Go to checkout</a></p>
<form method="post" action="/cart/empty"><button class="quiet" type="submit">Empty the cart</button></form>`,
  );
}

function checkoutPage(error = '') {
  if (state.cart.length === 0) {
    return page('Checkout', `<p class="muted">Your cart is empty. Add something first.</p><p><a href="/products">Go to products</a></p>`);
  }
  const summary = cartSummary(state.cart, state.discountCode);
  return page(
    'Checkout',
    `<p>You pay ${formatEuro(summary.totalCents)}.</p>
${error === '' ? '' : `<p class="error" role="alert">${escapeHtml(error)}</p>`}
<form method="post" action="/checkout">
  <label for="name">Name</label>
  <input id="name" name="name" maxlength="80" autocomplete="name">
  <label for="email">Email</label>
  <input id="email" name="email" type="email" maxlength="120" autocomplete="email">
  <label for="password">Password</label>
  <input id="password" name="password" type="password" maxlength="120" autocomplete="current-password">
  <p><button type="submit">Place order</button></p>
</form>`,
  );
}

function ordersPage(url) {
  const asked = url.searchParams.get('status') ?? 'All';
  const status = asked === 'All' || ORDER_STATUSES.includes(asked) ? asked : 'All';
  const shown = filterOrders(state.orders, status);
  const options = ['All', ...ORDER_STATUSES]
    .map((name) => `<option${name === status ? ' selected' : ''}>${name}</option>`)
    .join('');
  const rows = shown
    .map(
      (order) =>
        `<tr><td>${order.number}</td><td>${escapeHtml(order.customer)}</td><td>${order.status}</td><td>${formatEuro(order.totalCents)}</td></tr>`,
    )
    .join('');
  return page(
    'Orders',
    `<form method="get" action="/orders">
  <label for="status">Status</label>
  <select id="status" name="status">${options}</select>
  <button type="submit">Filter</button>
</form>
<p class="muted">${shown.length} ${shown.length === 1 ? 'order' : 'orders'}</p>
<table><thead><tr><th>Order</th><th>Customer</th><th>Status</th><th>Total</th></tr></thead><tbody>${rows}</tbody></table>`,
  );
}

function addToCart(productId) {
  if (findProduct(productId) === null) {
    return false;
  }
  const line = state.cart.find((candidate) => candidate.productId === productId);
  if (line === undefined) {
    state.cart.push({ productId, quantity: 1 });
  } else {
    line.quantity = Math.min(MAX_QUANTITY, line.quantity + 1);
  }
  return true;
}

function changeQuantity(productId, change) {
  const line = state.cart.find((candidate) => candidate.productId === productId);
  if (line === undefined || (change !== 1 && change !== -1)) {
    return false;
  }
  line.quantity = Math.min(MAX_QUANTITY, line.quantity + change);
  if (line.quantity === 0) {
    state.cart = state.cart.filter((candidate) => candidate !== line);
  }
  return true;
}

function placeOrder(form) {
  const name = (form.get('name') ?? '').trim();
  const email = (form.get('email') ?? '').trim();
  const password = form.get('password') ?? '';
  if (name === '' || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email) || password.length < 4) {
    return 'Fill in your name, a valid email and a password of 4 or more characters.';
  }
  const number = Math.max(...state.orders.map((order) => order.number)) + 1;
  const summary = cartSummary(state.cart, state.discountCode);
  state.orders.push({ number, customer: name.slice(0, 80), status: 'Placed', totalCents: summary.totalCents });
  state.cart = [];
  state.discountCode = null;
  state.flash = `Order ${number} placed.`;
  return '';
}

function readForm(request) {
  return new Promise((resolve, reject) => {
    let size = 0;
    const chunks = [];
    request.on('data', (chunk) => {
      size += chunk.length;
      if (size <= MAX_BODY_BYTES) {
        chunks.push(chunk);
      }
    });
    request.on('end', () => {
      if (size > MAX_BODY_BYTES) {
        reject(new Error('too large'));
        return;
      }
      resolve(new URLSearchParams(Buffer.concat(chunks).toString('utf8')));
    });
    request.on('error', reject);
  });
}

function send(response, status, html) {
  response.writeHead(status, { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store' });
  response.end(html);
}

function redirect(response, location) {
  response.writeHead(303, { Location: location });
  response.end();
}

async function route(request, response) {
  const url = new URL(request.url, `http://localhost:${PORT}`);
  const path = url.pathname;

  if (request.method === 'GET') {
    switch (path) {
      case '/':
        return redirect(response, '/products');
      case '/products':
        return send(response, 200, productsPage(url));
      case '/cart':
        return send(response, 200, cartPage());
      case '/checkout':
        return send(response, 200, checkoutPage());
      case '/orders':
        return send(response, 200, ordersPage(url));
      case '/health':
        response.writeHead(200, { 'Content-Type': 'text/plain' });
        return response.end('ok');
    }
    return send(response, 404, page('Not found', '<p>There is no such page.</p>'));
  }

  if (request.method !== 'POST') {
    response.writeHead(405, { Allow: 'GET, POST' });
    return response.end();
  }

  let form;
  try {
    form = await readForm(request);
  } catch {
    return send(response, 413, page('Too large', '<p>That form was too large.</p>'));
  }

  switch (path) {
    case '/cart/add':
      if (!addToCart(form.get('product') ?? '')) {
        return send(response, 404, page('Not found', '<p>There is no such product.</p>'));
      }
      state.flash = 'Added to the cart.';
      return redirect(response, '/cart');
    case '/cart/quantity':
      if (!changeQuantity(form.get('product') ?? '', Number(form.get('change')))) {
        return send(response, 404, page('Not found', '<p>That product is not in the cart.</p>'));
      }
      return redirect(response, '/cart');
    case '/cart/discount': {
      const code = (form.get('code') ?? '').trim().toUpperCase();
      if (!Object.hasOwn(DISCOUNT_CODES, code)) {
        return send(response, 422, cartPage('That code does not work.'));
      }
      state.discountCode = code;
      state.flash = `Code ${code} applied.`;
      return redirect(response, '/cart');
    }
    case '/cart/empty':
      state.cart = [];
      state.discountCode = null;
      return redirect(response, '/cart');
    case '/checkout': {
      if (state.cart.length === 0) {
        return redirect(response, '/checkout');
      }
      const error = placeOrder(form);
      if (error !== '') {
        return send(response, 422, checkoutPage(error));
      }
      return redirect(response, '/orders');
    }
    case '/reset':
      state = freshState();
      state.flash = 'Demo data reset.';
      return redirect(response, '/products');
  }
  return send(response, 404, page('Not found', '<p>There is no such page.</p>'));
}

createServer((request, response) => {
  route(request, response).catch((error) => {
    console.error(error);
    if (!response.headersSent) {
      send(response, 500, page('Something went wrong', '<p>Reload the page, or press Reset demo data.</p>'));
    }
  });
}).listen(PORT, () => {
  console.log(`Acme Foods shop on http://localhost:${PORT}`);
});
