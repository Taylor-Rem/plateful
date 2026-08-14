---
# ── Story template ──────────────────────────────────────────────────────────
# Copy this file, rename it to your-post-slug.md (the filename becomes the
# URL: /stories/your-post-slug — lowercase letters, numbers, hyphens only),
# fill in the front matter, and write markdown below the closing ---.
# Publishing = commit + push. This TEMPLATE.md file itself is never rendered.

# Required. The headline, <title>, og:title, and JSON-LD headline.
title: 'Your headline here'

# Required. Publish date (quote it, YYYY-MM-DD). Future-dated posts stay
# hidden in production until the date arrives; index sorts newest first.
date: '2026-08-13'

# Recommended. One or two sentences. Becomes the meta description, the
# index-card teaser, and the RSS item description. Aim for under ~160 chars.
excerpt: 'One-sentence summary used for SEO meta description and the index card.'

# Optional. Hero image path (put files in public/images/stories/). Shown at
# the top of the article, as the index thumbnail, and as og:image.
hero: /images/stories/your-post-slug.jpg

# Optional. Byline. Defaults to the line below when omitted — the founder
# disclosure is deliberate: readers should know who's writing.
author: 'Taylor Remund, founder of Plateful'

# Optional. e.g. [profile] for owner interviews, [explainer] for
# ordering/delivery-economics pieces.
tags: [profile]

# Required in practice. false (or omitted) = draft: visible locally for
# proofreading, 404 in production, excluded from sitemap.xml and the feed.
published: false
---

Write your story here in markdown. Headings (`##`), lists, links, images,
blockquotes, and tables all work.

A single soft CTA linking to /for-restaurants is appended automatically after
the article body — don't add ad breaks in the text.
