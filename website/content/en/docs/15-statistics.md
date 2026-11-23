---
title: Statistics
description: Visitors and page views of the websites, counted from the logs.
group: More
icon: chart-bar
slug: statistics
---

Under **System → Statistics** you see how many visitors your websites have. Once an hour the panel counts the access log of every website on each server. Nothing changes on the websites themselves: no script, no cookies, nothing for the cookie banner.

## What you see

- **Websites:** all websites with the visitors of today, the last 7 and the last 30 days and the page views of the last 30 days, the most visited first.
- **Chart:** visitors per day for 7, 30 or 90 days or per month for 14 months, for all websites together or for one. Hovering over a bar also shows the page views.
- **Most visited pages:** when you choose a website, its ten most visited pages in the chosen period are listed below.

For now only administrators see the statistics.

## How it is counted

- **Visitors:** one IP address with one browser on one day. Over several days the days are added up; whoever comes on two days counts twice.
- **Page views:** only pages delivered successfully. Not counted are images, stylesheets, scripts and other files, errors such as 404, redirects, form submissions and recognisable bots such as search engines or monitoring.
- The first time the panel counts up to 14 days back, as far as the logs on the server reach. After that it counts today and yesterday again every hour.

The values are estimates, as with any evaluation of logs: several people behind one router with the same browser count as one visitor, a bot with an ordinary browser name counts too.

## Privacy

Counting happens on the server. IP address and browser are made unrecognisable only for counting, with a key that is thrown away after the run. Only numbers per day and the most visited paths reach the panel, no addresses. The numbers are kept for 14 months. The server deletes the access logs themselves after 14 days as before.
