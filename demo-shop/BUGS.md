# The planted bugs (answers)

All four are in `src/shop.js`. `npm test` has one failing test for each.

| # | Where you see it | How to make it show | What is wrong |
|---|---|---|---|
| 1 | `/cart` Total | Add the same product twice (or press +) | The total counts each line once and ignores the quantity. |
| 2 | `/products` search | Search `Bread` (capital B) | Search is case-sensitive: `bread` finds Sourdough bread, `Bread` finds nothing. |
| 3 | `/cart` Total | Add only Oat milk, apply `WELCOME10` | The Total line disappears when a code is used on a cart under €10. |
| 4 | `/orders` filter | After **Reset demo data**, choose **Shipped** | Shows 2 orders, but 3 are shipped. The last order in the list is never checked. A new order you place goes missing under **Placed** the same way. |

The checkout **Password** field is there on purpose: the pinround scenario player must refuse to type into it.
