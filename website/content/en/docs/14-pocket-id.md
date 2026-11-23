---
title: Sign-in with Pocket ID
description: Sign in with a passkey, through your own Pocket ID instance.
group: More
icon: fingerprint
slug: pocket-id
---

Besides password and two-factor you can sign in to the panel with a passkey. For this OWPanel uses [Pocket ID](https://pocket-id.org), a lean sign-in service you run yourself. Any other OpenID Connect provider works the same way.

Pocket ID is an additional way, not a replacement: password and two-factor stay available for every account. If Pocket ID cannot be reached, you sign in with your password as usual.

## Prepare Pocket ID

1. In Pocket ID, create a new client under **OIDC Clients**, for example “OWPanel”.
2. Enter as **callback URL** the address the panel shows under **System → Pocket ID**. It looks like `https://panel.example.com/auth/oidc/callback`.
3. Turn on **PKCE**.
4. Note **client ID** and **client secret**.

::: tip
If you limit the client in Pocket ID to certain user groups, customers who should use Pocket ID must be in one of these groups as well. Otherwise Pocket ID already refuses the sign-in.
:::

## Set it up in the panel

Under **System → Pocket ID** you find the settings:

| Field | Content |
|---|---|
| Name of the Pocket ID instance | What the service is called in the panel, such as “goettlichIT ID”. It appears on the sign-in page as “Sign in with …”. Empty means “Pocket ID”. |
| Address of the instance | The address of your Pocket ID instance with `https://`, such as `https://login.example.com` |
| Client ID and client secret | From the OIDC client in Pocket ID. The secret is stored encrypted and never shown again. |

When you **save**, the panel checks the connection: the instance must answer under exactly this address, use only `https`, offer PKCE and sign its sign-ins. If something does not fit, the panel tells you what is missing and stores nothing. **Check connection** repeats the check at any time.

When the card is green, the sign-in is set up. To turn it off, clear the address and save; linked accounts stay linked as long as the address stays the same later.

## Link your account

Every account links itself once; nobody can link someone else's account:

1. Sign in to the panel with your password.
2. Open **My account → Sign-in and security**.
3. Choose **Link with …** and sign in at Pocket ID with your passkey.

From then on **Sign in with …** on the sign-in page is enough. On the same page you remove the link again. At sign-in the panel finds a Pocket ID account only through this link, never by e-mail address.

Administrators are not asked for the panel's two-factor code after a sign-in through Pocket ID, because the passkey already is a strong second factor.

## Pocket ID for customers

Administrators may always use Pocket ID. Customers only after you allow it: under **Customers → customer → Access**, in the card with the name of your instance, with **Allow …**. **Revoke …** takes it back and removes an existing link.

Without further setup the customer then links his account himself, as described above.

With an **API key of Pocket ID** under **System → External services** the panel does it for you:

1. In Pocket ID, under **Settings → Account**, create an API key with only the scopes `users:read`, `users:write` and `users:login-codes` and give it an expiry date. Scopes for API keys exist from Pocket ID 2.19; with older versions a key may do everything.
2. Enter the key in the panel. The panel checks it and stores it encrypted.
3. On the customer's page **Access**: **Search in …**. If there is an account with his email address, the panel shows it, and you link it with **Link this account**. If there is none, **Create in … and link** creates the account and links it at once.
4. **Show login link** creates a link with which the customer sets up his passkey (works 72 hours and once). **Send login link by email** lets Pocket ID send the link itself; this needs mail set up in Pocket ID.

Faster still when creating the access: with **Also create in … and send a login link** the panel creates the customer in Pocket ID, links him and lets Pocket ID send the login link. If Pocket ID already has an account with his address, the panel does not link it by itself; then you search for it as above and confirm it.

**Unlink** only removes the link in the panel; the account in Pocket ID stays.

## What the audit log shows

Sign-ins through Pocket ID, failed attempts, linking and removing links, turning it on and off and every change of the settings appear in the audit log under **System → Audit log**.
