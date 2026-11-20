// Shop rules. Money is in cents everywhere, so sums never pick up float errors.

export const PRODUCTS = [
  { id: 'bread', name: 'Sourdough bread', priceCents: 320 },
  { id: 'oat-milk', name: 'Oat milk', priceCents: 185 },
  { id: 'tomatoes', name: 'Tomatoes 1 kg', priceCents: 240 },
  { id: 'cheddar', name: 'Cheddar', priceCents: 410 },
  { id: 'apples', name: 'Apples, pack of 6', priceCents: 295 },
  { id: 'coffee', name: 'Coffee beans', priceCents: 850 },
];

export const ORDER_STATUSES = ['Placed', 'Shipped', 'Delivered'];

export const DISCOUNT_CODES = { WELCOME10: 10 };

export function seedOrders() {
  return [
    { number: 1001, customer: 'Lena', status: 'Placed', totalCents: 1240 },
    { number: 1002, customer: 'Omar', status: 'Shipped', totalCents: 505 },
    { number: 1003, customer: 'Mia', status: 'Delivered', totalCents: 2210 },
    { number: 1004, customer: 'Raj', status: 'Shipped', totalCents: 850 },
    { number: 1005, customer: 'Sara', status: 'Placed', totalCents: 650 },
    { number: 1006, customer: 'Lena', status: 'Shipped', totalCents: 1695 },
  ];
}

export function findProduct(id) {
  return PRODUCTS.find((product) => product.id === id) ?? null;
}

export function searchProducts(text) {
  const words = text.trim();
  if (words === '') {
    return PRODUCTS;
  }
  return PRODUCTS.filter((product) => product.name.includes(words));
}

/** @param {{productId: string, quantity: number}[]} lines */
export function cartTotal(lines) {
  let totalCents = 0;
  for (const line of lines) {
    const product = findProduct(line.productId);
    if (product !== null) {
      totalCents += product.priceCents;
    }
  }
  return totalCents;
}

/**
 * @param {{productId: string, quantity: number}[]} lines
 * @param {string|null} code a code from DISCOUNT_CODES, or null
 */
export function cartSummary(lines, code) {
  const subtotalCents = cartTotal(lines);
  const percent = code === null ? 0 : (DISCOUNT_CODES[code] ?? 0);
  const discountCents = Math.round((subtotalCents * percent) / 100);
  const totalCents = subtotalCents - discountCents;
  return {
    subtotalCents,
    discountCents,
    totalCents,
    showTotal: discountCents === 0 || subtotalCents >= 1000,
  };
}

/** @param {string} status 'All' or one of ORDER_STATUSES */
export function filterOrders(orders, status) {
  if (status === 'All') {
    return orders;
  }
  const matching = [];
  for (let i = 0; i < orders.length - 1; i++) {
    if (orders[i].status === status) {
      matching.push(orders[i]);
    }
  }
  return matching;
}

export function formatEuro(cents) {
  return `€${(cents / 100).toFixed(2)}`;
}
