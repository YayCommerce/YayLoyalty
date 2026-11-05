# Guest Cart Earn Line Design

**Status:** accepted 2026-11-05

- **Goal:** the guest sign-in card on cart and checkout says what the cart would earn: "Sign in to earn 20 Points with this order."
- **Out of scope:** the wording for a guest who creates an account after ordering, a Design tab switch, and the thank-you page.
- **Acceptance:** a guest with a cart that earns 12 points reads "Sign in to earn 12 points with this order." on the Cart and Checkout blocks and on classic pages; a cart that earns 0, or a paused program, keeps "Sign in to spend your Points."
- **Approach:** `ProductEarnMessage::guest_cart_points()` evaluates `ContextFactory::from_cart()`; `RedeemCard::markup()` passes it to `templates/redeem-card.php`.
- **Verification:** signed out, add a product and open Cart and Checkout: the line shows the cart's points, and changing the quantity updates it.
