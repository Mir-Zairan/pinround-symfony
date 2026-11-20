// These tests describe how the shop should work. Four of them fail on purpose while the planted bugs are in.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { cartSummary, cartTotal, filterOrders, formatEuro, searchProducts, seedOrders } from '../src/shop.js';

test('the cart total counts every unit of every line', () => {
  const lines = [
    { productId: 'bread', quantity: 2 },
    { productId: 'cheddar', quantity: 1 },
  ];
  assert.equal(cartTotal(lines), 2 * 320 + 410);
});

test('the cart total skips a product that no longer exists', () => {
  assert.equal(cartTotal([{ productId: 'gone', quantity: 3 }]), 0);
});

test('search ignores upper and lower case', () => {
  const names = searchProducts('Bread').map((product) => product.name);
  assert.deepEqual(names, ['Sourdough bread']);
});

test('an empty search lists every product', () => {
  assert.equal(searchProducts('   ').length, 6);
});

test('the total stays visible after a discount on a small cart', () => {
  const summary = cartSummary([{ productId: 'oat-milk', quantity: 1 }], 'WELCOME10');
  assert.equal(summary.discountCents, 19);
  assert.equal(summary.totalCents, 166);
  assert.equal(summary.showTotal, true);
});

test('an unknown code gives no discount', () => {
  const summary = cartSummary([{ productId: 'coffee', quantity: 1 }], 'NOPE');
  assert.equal(summary.discountCents, 0);
  assert.equal(summary.totalCents, 850);
});

test('the status filter keeps every matching order, the last one too', () => {
  const shipped = filterOrders(seedOrders(), 'Shipped').map((order) => order.number);
  assert.deepEqual(shipped, [1002, 1004, 1006]);
});

test('the status filter leaves out other statuses', () => {
  const delivered = filterOrders(seedOrders(), 'Delivered').map((order) => order.number);
  assert.deepEqual(delivered, [1003]);
});

test('euros show two decimals', () => {
  assert.equal(formatEuro(1695), '€16.95');
  assert.equal(formatEuro(5), '€0.05');
});
