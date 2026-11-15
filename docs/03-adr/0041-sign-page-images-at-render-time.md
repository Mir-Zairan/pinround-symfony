# ADR-0041 — Sign page images at render time, in a stable minute window

A page signs the view and thumb URLs of its pictures while it renders, after its own visibility check; supersedes the reading part of ADR-0011.

## Index

- [Status](#status) — Accepted, with the date.
- [Context and problem statement](#context-and-problem-statement) — what ADR-0011 said and why the page needs more.
- [Decision drivers](#decision-drivers) — speed, access control, caching.
- [Considered options](#considered-options) — redirect route, per-second signing, a stable window.
- [Decision outcome](#decision-outcome) — the chosen option and its consequences.
- [Confirmation](#confirmation) — the tests that pin it.
- [Pros and cons of the options](#pros-and-cons-of-the-options) — the trade-offs.
- [More information](#more-information) — related ADRs and revisit triggers.

## Status

Accepted · 2026-11-15 · Deciders: product owner.

It supersedes the "Reading" rules of [ADR-0011](0011-screenshot-storage-and-processing.md) in part: that templates hold only `/media/{id}/{variant}` links, and that render-time signing is for thumbnails only. The rest of ADR-0011 (private bucket, presigned uploads, the worker, 5-minute lives) stands.

## Context and problem statement

Lena opens a round with 30 screenshots. Each one needs a picture in the full view size, and the thumbnails sit beside them. With one `/media/{id}/{variant}` link per picture, the browser makes 60 extra requests to the app, and each one loads the voter and the media row before it redirects. Omar reloads the page and Turbo swaps every picture, because each render signed its URLs at the exact second.

How does a page show a whole set of private pictures with one read, without giving up the access check?

## Decision drivers

- A round page must not do one query per picture (performance-and-scale.md: no N+1).
- Only a viewer who may see the ticket or round may get a URL (ADR-0013, ADR-0017).
- The browser should cache a picture and not reload it on every render.

## Considered options

1. Keep only `/media/{id}/{variant}` redirect links in templates (ADR-0011 as written).
2. Sign both variants at render time, at the exact second.
3. Sign both variants at render time, with a signing time rounded to a fixed window.

## Decision outcome

Chosen option: **3**, because it gives one batched read per page and keeps the same URL for renders inside a window, which lets the browser cache it.

- `Media\Api\SignedUrls::forVariants(OrganizationId, mediaIds, variant, at)` signs `view` or `thumb`, for at most 200 ids, in one query. It takes no user: the caller (Review, Ticketing) must already have decided with its own voter that the viewer may see the owner of the pictures.
- The signing time is rounded down to the start of the minute and the URL works 5 minutes from there, so at least 4 minutes remain. The URL carries `response-cache-control=private, max-age=240, immutable`.
- Pending and rejected pictures get no URL; the answer says which, and the page shows a placeholder.
- The `/media/{id}/{variant}` redirect route is not built. It stays an option for links outside a page (an email, a shared link), where the route's own voter check would apply.

### Consequences

- Good: one query per page; a stable URL per minute, so the browser caches and Turbo morphs do not flicker.
- Bad: a URL can be passed on and works for up to 5 minutes; as in ADR-0011, accepted.
- Bad: a caller that skips its visibility check leaks URLs. The interface says so.
- Neutral: a browser that holds a page open longer than 4 minutes reloads a picture with an expired URL; refreshing URLs on a page that stays open is not built yet.

## Confirmation

- `tests/Unit/Media/Infrastructure/Storage/PresignedDownloadsTest`: same URL inside a minute, a new one in the next, expiry never under 240 seconds after the call, the cache-control parameter.
- `tests/Unit/Media/Application/SignedVariantUrlsTest` and `tests/Integration/Media/SignedUrlsTest`: the cap, the tenant check, one read per call.
- `tests/Integration/Media/Storage/PresignedDownloadsMinioTest`: the storage serves the bytes inline and refuses an old URL.

## Pros and cons of the options

### Redirect links only

- Good, because the voter runs on every picture.
- Bad, because every picture costs a request, a voter run and a read.

### Sign at the exact second

- Good, because it is the simplest.
- Bad, because every render makes new URLs: no browser caching, and Turbo swaps every picture.

### Sign in a minute window

- Good, because of the one batched read and the stable URL.
- Bad, because a URL's life is between 4 and 5 minutes, not exactly 5.

## More information

- Related: [ADR-0011](0011-screenshot-storage-and-processing.md), [ADR-0013](0013-authorization.md), [media-pipeline.md](../02-architecture/media-pipeline.md).
- Revisit when: pages show more than 200 pictures at once, or a link outside a page needs a picture.
