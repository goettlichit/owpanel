---
title: DNS
description: Check the DNS records of a website, create and correct them at Hetzner and remove them on deletion.
group: Operation
icon: world-search
slug: dns
---

For a website to be reachable and to get a certificate, its DNS records must point to the server. The **DNS** page in the menu of every website shows whether they do. If the domain is in a zone of the [Hetzner Cloud](https://www.hetzner.com/cloud), the panel can also create missing records itself. Only administrators see the page.

## What the page shows

The panel only looks at the records a website needs: **A** (IPv4) and **AAAA** (IPv6) for the domain and, if turned on, for the www address. MX, TXT and other records stay with your DNS provider.

All columns show the IP address the record points to, just seen from different places. If they differ, you see where things are stuck.

| Column | Meaning |
|---|---|
| Target | the addresses of the server |
| Hetzner zone | what is entered in the zone at Hetzner, with TTL (only if the panel finds the zone through the API) |
| Name server answer | what the responsible name servers of the domain deliver right now; the panel asks them directly |
| Resolver answer | what a normal caching resolver answers, here the one of the panel server; browsers and Let's Encrypt ask the same way |

Every record gets a state:

- **correct:** name servers and resolver point exactly to the server.
- **propagating:** the name servers are right, resolvers still know the old state. This resolves itself at the latest when the old TTL runs out.
- **missing:** there is no record.
- **different:** the record points to another address or holds an extra one.
- **unknown:** nobody answered.

Answers are cached for five minutes. **Check DNS** asks again at once. The check through the name servers works with every DNS provider, not only Hetzner.

## Set up the Hetzner token

To create and correct records, the panel needs an API token of the Hetzner project that holds the DNS zones. Enter it under **System → External services**.

- If servers and zones are in the same project, the token under **Hetzner API**, which the panel also uses for snapshots, is enough.
- If the zones are in a project of their own, enter that project's token under **Hetzner API for DNS**. This is often the case after the move away from the old Hetzner DNS Console: Hetzner put the zones into a project "DNS Migrated".

Read access is enough to show records; to create and correct them the token needs **read and write**. The panel checks the token on saving and stores it encrypted.

## Create and correct records

If the panel finds the zone, the table shows **Create** for missing and **Correct** for different records, and the overview also offers **Correct all**. Each of them first leads to a confirmation page with the current and the new value. There you choose the TTL (5 minutes to 1 day). Nothing changes before **Set records**.

Afterwards every record holds exactly the address of the server; a second address in the same record is replaced. Every change appears in the audit log with old and new value.

::: tip
If the name servers point to the server but the certificate is still missing, the page offers **Request certificate** at the top. This happens when a website was created before its DNS records were right.
:::

## When creating a website

With a Hetzner token, the **New website** form shows the option **Create DNS records at Hetzner**. It is preselected. The panel then creates the missing records before it sets up the website, so the certificate works right away. It never overwrites existing records; if one points elsewhere, the message afterwards tells you, and you correct it on the **DNS** page.

## When deleting a website

**Delete website** in the website's menu leads to a confirmation page. It lists the DNS records the panel set for this website:

- **can go:** the record still points exactly to the address the panel set.
- **stays:** someone changed the record in the meantime.

With the tick **Also delete the DNS records the panel created**, the panel removes the records marked "can go" once the website is deleted on the server. Without the tick everything stays. The panel never touches records you created yourself at your DNS provider.
