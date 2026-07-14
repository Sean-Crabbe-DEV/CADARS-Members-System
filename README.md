# CADARS Members System

Self-hosted membership system for Chepstow & District Amateur Radio Society.

## Email sending

The Email system config page supports:

- PHP mail()
- Resend API
- SMTP settings stored for future SMTP wiring

For Resend API:

1. Go to Committee > Emails.
2. Click Email system config as an admin.
3. Set Mail method to Resend API.
4. Enter the Resend API key.
5. Set the From email to an address/domain allowed in Resend.
6. Save and send a test email.

Bulk emails with more than one recipient are sent using BCC for member privacy.

## Dashboard events update

Dashboard now shows only the next three events within the next month. User roles and attendance summary have been removed from the dashboard to keep it cleaner.

## Dashboard layout

Dashboard order adjusted so Notifications appear in the top dashboard grid and Next events appears below.

## Login and password recovery

The login page has been restyled with a responsive centred sign-in card. Users can now use **Forgot password?** to request a self-service password reset link. Reset links use the existing secure token system and expire after 48 hours.

## User linked member update

The Users admin page now allows admins to change which member record is linked to an existing user account, or unlink the user from a member record. Changes are audit logged with old and new linked member values.

## Wallet settings page

Added Admin > Wallet settings. Admins can upload Apple Wallet pass certificate files, private key, WWDR certificate, certificate password, and Google/Android Wallet issuer/API details including service account JSON. Uploaded credential files are stored under the private storage folder rather than the public web folder.

## SMTP sending and test email

- Implemented SMTP sending with STARTTLS/TLS, SSL/SMTPS, or trusted unencrypted SMTP.
- Supports SMTP AUTH LOGIN with AUTH PLAIN fallback.
- SMTP sends support HTML email, BCC envelope recipients, Reply-To, and attachments.
- Email settings now have a Send test email button which saves the current configuration and sends a test using the selected transport.
- SMTP/Resend secret fields no longer display saved secrets; leaving them blank retains the existing value.

## Door tax module

Added a Door tax module under the Committee menu. Access is restricted to Chair, Vice Chair, Secretary and Treasurer. The module tracks prepaid door tax balances, payments, manual charges/adjustments, and attendance-based deductions from past events. Members can see their door tax balance and number of meetings covered on their own profile. Member admin pages also show the door tax balance and link to management. Attendance pages link into Door tax so attended events can be charged.

## Physical membership cards

Added a Membership cards page linked from the Committee Members area, Admin menu, member list and Wallet cards page. Cards use the ISO/IEC 7810 ID-1 credit-card dimensions of 85.60 × 53.98 mm and show the club logo, member name, membership number, join date and the same QR verification link used by Wallet cards. Members can open their own card from My Profile. The Wallet settings page now includes a private club logo upload.

## Membership card join date display

Membership cards now leave the join date value blank when the member is marked as having joined before system records, or when no join date is recorded.

## GW4LWZ membership-card branding

- Join date is blank on cards for members marked as joined before system records.
- Bundled the official GW4LWZ transparent logo as the default card logo.
- Membership and wallet card headings use GW4LWZ instead of the full club name.
- A custom logo uploaded through Wallet settings still overrides the bundled logo.

## Membership card layout refinement

Refined the physical membership-card layout to better match the visual design. The QR code now sits properly centred inside the white box, spacing is more balanced, the name and metadata layout is cleaner, and the print-preview/open-card presentation is tidier without changing the card size.

## Membership card print pagination update

Adjusted the physical membership-card print styles so cards are not split across pages and print in batches of up to 8 per A4 sheet. Also tightened the logo/heading spacing so the GW4LWZ title sits closer to the club logo.

## Membership card links page

Changed the Membership cards page to a member list with separate columns for Physical card, Apple Wallet and Android/Google Wallet. Added a top-level View / print all physical cards button, which opens a dedicated print view containing all member cards. Wallet links automatically load the selected member and relevant platform on the Wallet cards page.

## My Profile section order and wallet links

Reordered My Profile so subscription/payment history appears before Door tax, with Door tax immediately below subscriptions. Moved the Membership cards section to the bottom of the page and added personal Physical, Apple Wallet and Android/Google Wallet links. The wallet links use a self-service route that only loads the member linked to the logged-in user.
