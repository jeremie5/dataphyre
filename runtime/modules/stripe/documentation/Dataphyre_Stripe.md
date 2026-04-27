### Stripe Module

The **Stripe** module in Dataphyre provides a set of functionalities to integrate with Stripe’s payment processing system. This module facilitates a variety of Stripe operations including creating customers and accounts, handling payments, managing webhooks, retrieving platform balances, and handling payment methods.

#### Key Functionalities

1. **Account Management**: Create and manage Stripe accounts for the platform and customers.
2. **Payment Processing**: Create and manage payment intents, handle new payment methods, and process payments.
3. **Webhook Handling**: Securely verify and process incoming Stripe webhooks.
4. **Payouts and Transfers**: Initiate transfers and payouts to accounts.
5. **Payment Method Management**: Attach, retrieve, delete, and manage payment methods for customers.

---

#### Core Methods

1. **Platform Account Management**

   - **`get_platform_account_for_country($country)`**: Determines the appropriate platform account based on a country code.
   - **`set_platform_account($platform_account)`**: Sets the active platform account and API key for Stripe interactions.
   - **`get_publishable_key($platform_account)`**: Retrieves the publishable API key for the specified platform account, depending on the test/live mode.
   - **`get_secret_key($platform_account)`**: Retrieves the secret API key for the specified platform account.

2. **Customer and Account Creation**

   - **`create_customer($userid, $email, $name)`**: Creates a new Stripe customer using the user’s ID, email, and name.
   - **`create_account($params)`**: Creates a new Stripe account for platform or user purposes with specified parameters.
   - **`create_account_link($accountId, $return_url, $refresh_url)`**: Generates a link for onboarding Stripe accounts with specified return and refresh URLs.
   - **`check_account_status($accountId)`**: Retrieves the status of a Stripe account.

3. **Payment Intent and Payment Processing**

   - **`create_payment_intent($params)`**: Creates a new payment intent for processing payments.
   - **`check_payment_status($payment_intent_id)`**: Checks the status of an existing payment intent.
   - **`submit_payment($payment_intentId)`**: Confirms a payment intent, authorizing the payment.
   - **`cancel_payment($payment_intentId)`**: Cancels an existing payment intent.
   - **`submit_refund($payment_intent_id, $amount_to_refund)`**: Initiates a refund for a specific payment intent.
   - **`capture_payment_intent($payment_intentId)`**: Captures a previously authorized payment intent, finalizing the payment.

4. **Webhook Handling**

   - **`handle_webhook()`**: Validates and processes incoming Stripe webhook events. This includes verifying the signature and calling the appropriate handler function for the event type.

5. **Balance and Transfer Management**

   - **`get_platform_balance()`**: Retrieves the balance for the platform’s Stripe account.
   - **`initiate_transfer($params)`**: Initiates a transfer to a connected account.
   - **`create_payout($params, $options=[])`**: Creates a payout from the platform’s Stripe account.

6. **Payment Method Management**

   - **`handle_new_payment_method($stripe_token, $userid, $stripe_customer_id, $name_on_card, ?callable $no_customer_account_callback)`**: Handles a new payment method by attaching it to the user’s Stripe customer ID and saving its details in the database.
   - **`retrieve_payment_method($payment_method_id)`**: Retrieves a payment method by its ID.
   - **`delete_payment_method($payment_method_id)`**: Detaches and deletes a specified payment method from Stripe and the local database.
   - **`retrieve_all_payment_methods($customerId)`**: Retrieves all payment methods associated with a customer.

---

#### Example Usage

1. **Creating a Customer**
   ```php
   $customer = stripe::create_customer($userid, 'user@example.com', 'John Doe');
   ```

2. **Creating a Payment Intent**
   ```php
   $params = [
       'amount' => 5000,
       'currency' => 'usd',
       'payment_method_types' => ['card']
   ];
   $payment_intent = stripe::create_payment_intent($params);
   ```

3. **Handling a Webhook**
   ```php
   stripe::handle_webhook();
   ```

4. **Refunding a Payment**
   ```php
   $refund = stripe::submit_refund($payment_intent_id, 2000); // Refunds $20.00
   ```

5. **Retrieving Platform Balance**
   ```php
   $balance = stripe::get_platform_balance();
   ```

---

#### Workflow

1. **Account Setup and Management**: The module allows for the setup and management of Stripe accounts both for the platform and individual users, including account creation, linking, and status checking.
2. **Payment Processing**: Handles payment workflows from creating payment intents to confirming and capturing payments. It also supports refunds, cancellations, and payment status checks.
3. **Webhook Handling**: Verifies and processes Stripe webhooks to manage events such as payment completions and refunds.
4. **Payouts and Transfers**: Manages the transfer of funds to accounts or bank accounts, supporting various payout and transfer options.
5. **Payment Method Handling**: Supports attaching, detaching, and managing payment methods for users.

---

The **Stripe** module provides a comprehensive integration for managing payments, accounts, and financial workflows through Stripe within Dataphyre.