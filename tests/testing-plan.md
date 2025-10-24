# Testing Plan

## Test Matrix

| Variation | 2 Digital Products (1 Standard VAT, 1 Reduced VAT) | Subscription Purchase | Subscription Renewal | 2 Physical Products with Shipping (1 Standard VAT, 1 Reduced VAT) | 2 Physical Products without Shipping (1 Standard VAT, 1 Reduced VAT) |
|-----------|-----------------------------------------------------|----------------------|----------------------|-------------------------------------------------------------------|----------------------------------------------------------------------|
| **Buyer with EU VAT + Stripe** | [ ] | [ ] | [ ] | [ ] | [ ] |
| **Buyer with EU VAT + COD** | [ ] | [ ] | [ ] | [ ] | [ ] |
| **Buyer without EU VAT + Stripe** | [ ] | [ ] | [ ] | [ ] | [ ] |
| **Buyer without EU VAT + COD** | [ ] | [ ] | [ ] | [ ] | [ ] |

## Test Case Details

### Column 1: Purchase 2 Digital Products
- Product 1: Standard VAT rate
- Product 2: Reduced VAT rate
- No shipping required

### Column 2: Subscription Purchase
- Initial subscription sign-up
- Recurring payment setup

### Column 3: Subscription Renewal
- Automatic renewal process
- Recurring payment execution

### Column 4: Purchase 2 Physical Products with Shipping
- Product 1: Standard VAT rate
- Product 2: Reduced VAT rate
- Shipping costs included

### Column 5: Purchase 2 Physical Products without Shipping
- Product 1: Standard VAT rate
- Product 2: Reduced VAT rate
- No shipping costs (e.g., in-store pickup)

## Testing Variations

### EU VAT Status
- **With EU VAT**: Buyer has a valid EU VAT number
- **Without EU VAT**: Buyer does not have an EU VAT number

### Payment Methods
- **Stripe**: Online payment via Stripe
- **COD**: Cash on Delivery

## Notes
- Mark each cell with [x] when the test is completed
- Document any issues or edge cases discovered during testing
- Ensure invoice generation follows correct VAT rules for each scenario
