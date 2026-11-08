# Acceptance checklist — Platform edition

Do these checks after `AUTOMATED CHECKS PASSED.` using your **new version**, not the previous v1 folder. Keep the results as proof of what you actually verified.

## Accounts / permission model
- [ ] Member A and administrator sign in and sign out successfully.
- [ ] Member B cannot view Member A's request detail, audit or conversation (HTTP 404).
- [ ] Member cannot open `/admin/resources` (HTTP 403).
- [ ] Invalid CSRF token prevents form submission (HTTP 419).

## Inventory / allocation
- [ ] Admin sees the four sample pools under **Administration**.
- [ ] Catalog shows availability before submitting a request.
- [ ] Member creates a project and submits a request for two GPUs.
- [ ] Admin approves it; the GPU pool availability decreases by two.
- [ ] Admin tries to approve more units than a pool can supply: action is refused.
- [ ] Admin releases the first allocation; availability increases by two.
- [ ] Admin cannot reduce a pool capacity below the currently allocated quantity.
- [ ] Admin can add a new pool and mark it inactive; inactive pools cannot be selected for new requests.

## Project and collaboration
- [ ] Member can create a project and link a request to it.
- [ ] Member can archive own project. Existing linked requests remain readable.
- [ ] An archived project cannot be used for a new request.
- [ ] Member and administrator can exchange comments within an accessible request.
- [ ] Another member cannot read those comments or attach a request to someone else's project.

## Forms, reports and API
- [ ] Quantities 0 and 101 are refused; no row is saved.
- [ ] Invalid date range (end before start) is refused.
- [ ] Search, status and priority filters return expected requests.
- [ ] Analytics changes when requests are approved or released.
- [ ] CSV export contains only requests the signed-in user may access.
- [ ] `GET /api/requests?status=invalid` returns JSON with HTTP 400.
- [ ] `GET /api/resources` returns real capacity information.

## Responsive and browser checks
- [ ] At 390 × 844 px, **Overview**, **Requests**, **Catalog**, **Projects**, **Analytics**, **New request** and **Request details** display without horizontal page overflow.
- [ ] All forms and buttons are reachable with keyboard tab navigation.
- [ ] No critical errors appear in Firefox developer tools Network or Console tabs.
- [ ] Closing and reopening Docker without `-v` preserves accounts and requests.

## GitHub readiness
- [ ] No real passwords, private keys or access tokens are committed.
- [ ] README reflects the real installed version and passing tests.
- [ ] Screenshots are taken from the application itself.
- [ ] GitHub Actions (after publishing) show green, including integration and HTTP smoke tests.
- [ ] You can explain the database relationships, role authorization, capacity locking and release process at an interview.
