Install Profile Types
===========================================

This directory contains two additional profile types that will be
imported ONLY if the user selects to use multiple profile types for billing and
shipping. The profile types that will be created are:

- Billing Profile Type (customer_billing)
- Shipping Profile Type (customer_shipping)

This is the reason that these profile type are not in the default install
directory because by default commerce order will be using a single profile
type for both billing and shipping (customer).

So, we only want to create these profile types if the user wants to switch
to using multiple profile types for the billing and shipping.

