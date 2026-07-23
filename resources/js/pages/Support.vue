<script setup lang="ts">
import LegalLayout from '@/layouts/LegalLayout.vue';
</script>

<template>
    <LegalLayout title="Support" last-updated="July 16, 2026">
        <p>
            Plateful is an online ordering and loyalty platform for independent
            restaurants. This page explains how restaurant owners and staff can
            get help with their Plateful account, their ordering page, and any
            point-of-sale integration they have connected.
        </p>

        <h2>Contact Us</h2>
        <p>
            Email
            <a href="mailto:founder@plateful.fyi">founder@plateful.fyi</a> with
            any question, problem, or request. It reaches us directly — there is
            no ticket number to chase and no phone tree.
        </p>
        <p>
            We answer support email during US business hours and aim to reply
            within one business day. If your restaurant cannot take orders right
            now, write <strong>URGENT</strong> in the subject line and we will
            prioritize it.
        </p>

        <h3>What to include</h3>
        <p>
            The more of this you can give us up front, the faster we can fix
            things:
        </p>
        <ul>
            <li>Your restaurant name and your Plateful address.</li>
            <li>What you expected to happen, and what happened instead.</li>
            <li>
                The order number, if the problem relates to a specific order.
            </li>
            <li>Roughly when it happened, and a screenshot if you have one.</li>
        </ul>

        <h2>Common Questions</h2>

        <h3>How do I connect my point-of-sale system?</h3>
        <p>
            Open your Plateful dashboard and go to
            <strong>Settings → Delivery &amp; POS</strong>, then choose your
            provider and follow the prompts to authorize the connection. Once
            connected, Plateful orders are sent into your POS automatically as
            they come in. If the connection fails or you are not sure which
            account to authorize, email us and we will walk you through it.
        </p>

        <h3>An order did not reach my POS or kitchen. What now?</h3>
        <p>
            Every Plateful order is always visible in your dashboard under
            <strong>Orders</strong>, and on the <strong>Kitchen</strong> screen,
            even if it failed to reach your POS. Work from those screens so the
            order still goes out on time, then email us — we can see what
            happened to the injection and resend it.
        </p>

        <h3>How do I change my menu, prices, or hours?</h3>
        <p>
            Menu items and prices live under <strong>Menu</strong> in your
            dashboard, and your open hours live under <strong>Hours</strong>.
            Changes take effect on your ordering page right away. If your menu
            is synced from a connected POS, edit it in the POS and let it sync
            across rather than editing it in both places.
        </p>

        <h3>When do I get paid?</h3>
        <p>
            Card payments for your orders are processed by Stripe and paid out
            to the bank account you connected during onboarding, on Stripe's
            standard payout schedule. You can see your payouts under
            <strong>Payouts</strong> in your dashboard. If a payout looks wrong
            or has not arrived, email us with the date and amount.
        </p>

        <h3>How do I add or remove staff?</h3>
        <p>
            Restaurant owners can invite staff and remove access under
            <strong>Members</strong> in the dashboard. If you have lost access
            to the owner account itself, email us from the address the account
            was created with and we will help you recover it.
        </p>

        <h3>I want to close my account.</h3>
        <p>
            Email us and we will take your ordering page offline and close the
            account. Ordering stops immediately; you keep access to your order
            history and payout records for your records.
        </p>

        <h2>Customers</h2>
        <p>
            If you are a diner with a question about an order you placed — where
            it is, a refund, or something missing — contact the restaurant you
            ordered from directly. Each restaurant prepares and fulfills its own
            orders and can resolve those fastest. You can also reach us at
            <a href="mailto:founder@plateful.fyi">founder@plateful.fyi</a> if
            you cannot reach the restaurant or the problem is with the Plateful
            site itself.
        </p>

        <h2>Other Resources</h2>
        <p>
            See our <a href="/terms">Terms of Service</a> and
            <a href="/privacy">Privacy Policy</a> for the legal agreements
            covering your use of Plateful. For questions specifically about
            those, use the contact addresses listed on each.
        </p>
    </LegalLayout>
</template>
