PEPP LEARNING — ADMIN SYSTEM REBUILD
=====================================
Deployment package · June 2026

WHAT'S IN THIS PACKAGE
----------------------
Admin pages (rebuilt, light-pastel design matching dashboard.php):
  login.php                       dashboard.php
  student-approval.php            nonapproval-studentdetails.php
  studentonboarding.php           studentpage.php
  student-details.php             add-student.php
  phpinstalmentpaymentupdate.php  course-management.php
  settings.php
  update-student-details.php / get-student-details.php  (secured stubs)

Shared files (NEW — required):
  includes/auth.php          login guard, 2h session timeout, CSRF, audit helpers
  includes/admin_nav.php     shared sidebar + topbar (live pending-count badges)
  includes/admin_footer.php  closes the layout, modal/sidebar JS
  assets/css/admin-theme.css one shared pastel design system
  config/database.php        cleaned (no more CREATE TABLE on every request)

Student-facing files (from the earlier redesign, included for completeness):
  register.php (with new data fixes — see below), success.php,
  installmentpayment.php, logo.png

HOW TO DEPLOY
-------------
1. Upload EVERYTHING to the same folder where your current admin files live
   (the folder containing register.php), keeping the subfolders:
   includes/, assets/css/, config/.
2. Keep your existing payment-review.php and uploads/ folder — they are
   untouched and still used.
3. Log in with the current credentials (peppadmin / admin123@pepp), then go to
   Settings → Admin Account and SET A NEW PASSWORD immediately. From then on
   the password is stored as a secure hash in admin_settings and the default
   stops working.

BUGS FIXED
----------
1. add-student.php inserted into track_records with WRONG column names
   (action/description/admin_name) → the insert always failed and showed
   "Error adding student" even though the student WAS added. Now uses the
   real columns (action_type/action_details/performed_by).
2. Approving a student never set approved_by, approval_date, joined_date,
   total_fee, student_status — and wrote nothing to student_approval_history
   or student_status_log. Approval is now one DB transaction that fills all
   fields, schedules future installments (#1 = registration payment) and
   records full history.
3. Deleting a registration left orphan rows in instalment_details,
   installment_configuration and student_onboarding. Delete now removes
   children and stores a 'deleted' record in approval history first.
4. register.php saved NULL into mobile_same_as_whatsapp (wrong form key) and
   never filled the phone column (which the installment admin page reads).
   Both fixed.
5. update-student-details.php had NO LOGIN CHECK — anyone with the URL could
   edit student records. Replaced with an authenticated stub; editing now
   happens inside student-details.php with CSRF protection.
6. login.php displayed the admin credentials publicly and allowed unlimited
   password guesses. New login hides credentials, locks for 10 minutes after
   5 failed attempts, regenerates the session ID, and supports a hashed
   password stored in the database (changeable from Settings).
7. nonapproval-studentdetails.php linked to six pages that don't exist
   (students.php, invoices.php, admin-settings.php, …). All pages now share
   one sidebar with only real, working links — including course-management,
   which previously appeared in no menu at all.
8. dashboard.php showed FAKE fallback numbers (1,247 students) when a query
   failed, and monthly revenue ignored installment payments. Now shows real
   data only and revenue = registration payments + approved installments.
9. course-management.php used a hardcoded academic-year list; it now reads
   the academic_years table (managed in Settings). Deleting a course with
   enrolled students is blocked; renaming a course updates enrolled students
   so they stay linked.
10. phpinstalmentpaymentupdate.php read u.phone (always empty) for contact
    info; now uses the WhatsApp number. "Pending review" is precisely
    status='pending' AND paid_date IS NOT NULL — exactly what
    installmentpayment.php submits.
11. config/database.php ran a big CREATE TABLE block on EVERY page load,
    slowing the whole admin. Removed; DB errors no longer leak details to
    the browser.

SECURITY ADDED EVERYWHERE
-------------------------
- includes/auth.php on all 10 pages + 2h inactivity timeout + safe logout
- CSRF tokens verified on every form and AJAX action
- All output escaped (e() helper), all queries use prepared statements
- File uploads validated (type + 5 MB limit)
- Every admin action audited into track_records / student_status_log

HOW THE PAGES CONNECT NOW
-------------------------
register.php → users(status=pending)
  → Approvals (student-approval.php): view/edit (nonapproval-studentdetails),
    approve (sets fee/plan/installments) or reject/delete
  → Onboarding (studentonboarding.php): WhatsApp templates + checklist
  → All Students (studentpage.php) → full profile (student-details.php)
installmentpayment.php → instalment_details(paid_date + proof)
  → Installments (phpinstalmentpaymentupdate.php) → payment-review.php
Settings feeds: academic years, message templates, payment accounts,
admin credentials. The sidebar shows live pending counts for Approvals,
Installments and Onboarding on every page.

UPDATE 2 — PAYMENT REVIEW, WHATSAPP & REVENUE REPAIR
----------------------------------------------------
New / rebuilt files in this update:
  payment-review.php        rebuilt — matches the system design + fixed logic
  whatsapp-notification.php rebuilt — direct wa.me messaging like onboarding
  dashboard.php             perfected revenue model + Fee Collection Report
  phpinstalmentpaymentupdate.php  updated rejection semantics
  student-details.php       balance calculation fixed
  includes/admin_nav.php    "WhatsApp Messages" added to the sidebar
  database-update.sql       ★ RUN ONCE in phpMyAdmin (back up first!)

REVENUE BUGS FIXED
------------------
1. DOUBLE COUNTING: the old payment-review.php added every approved
   installment into users.paid_amount. Since installments are also summed
   from instalment_details, every installment counted TWICE in revenue.
   The code no longer touches users.paid_amount (it now holds the
   registration payment only) and database-update.sql subtracts the
   inflation back out of existing rows.
2. users.total_fee was 0 for all existing students (old approval never set
   it), so balances/outstanding had nothing to calculate from. The migration
   backfills total_fee = course fee − discount.
3. student-details.php subtracted the discount AGAIN from total_fee (which
   is already net of discount) — balances were under-stated. Fixed.
4. Approved installments had no paid_amount recorded — reports summing
   received money under-counted. Code now records it; migration backfills.
5. Old payment-review marked the COURSE 'completed' when all payments
   finished — paying in full is not finishing the course. Removed; the
   migration restores wrongly-completed active students.

THE REVENUE MODEL (now consistent everywhere)
---------------------------------------------
  users.paid_amount   = registration payment ONLY
  instalment_details  = each installment (received amount in paid_amount)
  users.total_fee     = net payable (course fee − discount)
  Collected           = registration + approved installments
  Outstanding         = net payable − collected (never negative)
Dashboard, student profile, payment review and the new Fee Collection
Report (monthly + per-course with progress bars) all use these same rules.

PAYMENT REVIEW & WHATSAPP FLOW
------------------------------
- Review page now opens ANY installment (read-only when already processed;
  the old page redirected to a non-existent file).
- Approve: records received amount, payment mode/account, extends course
  access, optionally reschedules the next installment — all in one
  transaction, fully logged.
- Reject: requires a reason, then CLEARS the proof and returns the
  installment to a payable state so the student can re-submit through
  installmentpayment.php (previously a rejected payment was stuck forever).
  The rejection stays recorded (rejected_by/at + remarks + logs) and shows
  as "Awaiting re-payment".
- After approve/reject a WhatsApp button opens wa.me with the message
  pre-filled (and it is logged) — same direct approach as onboarding.
- whatsapp-notification.php: pick a student (auto-fills phone + the
  {name}/{PEPP course}/{user_id} placeholders), start from a template,
  one click opens WhatsApp and logs the message. Recent-messages list with
  a resend button.

DEPLOY ORDER FOR THIS UPDATE
----------------------------
1. BACK UP the database (phpMyAdmin → Export).
2. Run database-update.sql ONCE (phpMyAdmin → SQL). Section 2 must not be
   run twice — the file explains a preview query you can run first.
3. Upload the updated PHP files (overwrite payment-review.php and
   whatsapp-notification.php this time — they are now part of the system).

UPDATE 3 — SUPER ADMIN, ROLES, ACTIVITY TRACKING & REPORTS
----------------------------------------------------------
New files:
  admin-management.php   create admins, grant page access, reset passwords
  admin-activity.php     filterable activity timeline + Excel export
  reports.php            Students / Courses / Revenue / Payments / Activity
                         reports, each with Export to Excel
  database-update-2.sql  ★ RUN ONCE in phpMyAdmin (after database-update.sql)

Updated files: includes/auth.php, includes/admin_nav.php, login.php,
settings.php, student-approval.php, course-management.php,
student-details.php, studentpage.php + permission checks on every page.

HOW ROLES WORK
--------------
• Your current peppadmin account becomes the SUPER ADMIN automatically
  (the migration copies your existing username/password — if you changed
  the password earlier in Settings, that password still works).
• Super Admin: every page, Admin Management, Activity Log, Reports &
  exports, and is the ONLY role that can delete data anywhere — pending
  registrations, courses, and full student records (new "Danger Zone" on
  the student profile). 2-hour session.
• Admins: see ONLY the pages granted to them — the sidebar hides
  everything else and direct URLs show an "Access Restricted" page.
  They cannot delete anything (buttons hidden AND server-side blocked).
  AUTO-LOGOUT after 20 minutes of inactivity (logged as auto_logout).
• Future pages: add one line to the page registry at the top of
  includes/auth.php and the new page instantly appears in Admin
  Management for granting.

ACTIVITY TRACKING
-----------------
• Every login and logout is recorded with date & time, IP ADDRESS and
  approximate LOCATION (city/region/country via ip-api.com, looked up
  once at login; private IPs show "Local / private network").
• Auto-logouts, forced logouts, admin creation/permission changes,
  password resets, data exports and student deletions are all logged.
• Activity Log merges sessions + every student action (track_records)
  + WhatsApp messages into one timeline, filterable by admin, type,
  date range and free text — with Excel export of the current filter.

REPORTS & EXPORT (Super Admin)
------------------------------
System → Reports & Export: Students (with per-student collected/balance),
Courses (collection & outstanding per course), Revenue (monthly
registration + installment collections), Payments (full installment
ledger) and Admin Activity. Every tab has filters and a one-click
"Export to Excel" (CSV with UTF-8 BOM — opens directly in Excel).
Exports are themselves recorded in the activity log.

DEPLOY ORDER FOR THIS UPDATE
----------------------------
1. Run database-update-2.sql once in phpMyAdmin.
2. Upload all PHP files (including includes/).
3. Log in with your current credentials — you are the Super Admin.
4. System → Admin Management to create restricted admin accounts.

UPDATE 4 — INVOICES, GST ACCOUNT REPORT, PER-COURSE REGISTRATION
----------------------------------------------------------------
New files:
  invoices.php           invoice list: filters, PDF download, resend email,
                         "Generate missing invoices" backfill
  invoice-pdf.php        streams any invoice as a PDF download
  includes/invoice_helper.php  numbering + GST split + auto generation
  includes/pdf_invoice.php     pure-PHP PDF engine (no libraries needed)
  includes/invoice_mailer.php  branded email with PDF attachment
  includes/template_helper.php WhatsApp {variable} auto-filler
  pepp-logo.jpg          ★ upload to the SITE ROOT (used on PDF invoices)
  database-update-3.sql  ★ RUN ONCE in phpMyAdmin (after update 1 and 2)

Updated: reports.php (Payment Accounts tab), settings.php (Invoice
Settings + template variable list), register.php (per-course duplicate
rule), student-approval.php / payment-review.php / add-student.php
(automatic invoicing), studentonboarding.php / whatsapp-notification.php
(DB-driven template variables), includes/auth.php + admin_nav.php
('invoices' page key — grantable to admins).

PAYMENT ACCOUNT REPORT (Reports & Export → Payment Accounts)
------------------------------------------------------------
Every approved payment grouped by receiving account with date filters.
The GST account (AXIS LABINC) is split: gross → taxable value (yours)
+ CGST 9% + SGST 9%; other accounts are fully yours. A totals row and a
GST summary show exactly how much GST is to be remitted. Export to Excel.

INVOICES
--------
• Generated AUTOMATICALLY when a payment is approved: registration
  approval, manual student add, and installment approval.
• AXIS LABINC receipts → GST invoice (GSTIN 32AAFCL3813L1ZL, HSN 9992,
  CGST/SGST tables, amount in words, logo) matching your sample format.
• Other accounts → professional receipt with NO tax mention.
• Numbering — GST: INV/2627/001 (prefix / FY code / incrementing
  sequence, validity dates managed in Settings; sequence used ONLY for
  GST invoices). Non-GST: INV/DDMMYY/001 (paid date + its own
  independent running sequence).
• Settings → Invoice Settings: GST account selector, both formats,
  series start/end dates, next sequence numbers with live previews.
• Students automatically receive a branded confirmation email with the
  PDF invoice attached, from payments@pepplearning.in (Reply-To
  noreply@). NOTE: create the payments@pepplearning.in mailbox in
  Hostinger → Emails, or sending will fail silently.
• Payments → Invoices: download PDFs, resend emails, and run
  "Generate missing invoices" once to backfill older approved payments
  (emails off by default for backfill).

REGISTRATION RULE CHANGE
------------------------
Email and WhatsApp duplicates are now checked per COURSE + academic
year (live AJAX checks and submit validation), so one student can join
multiple courses in the same year. database-update-3.sql swaps the DB
unique keys to match.

WHATSAPP TEMPLATE VARIABLES
---------------------------
All {variables} in Settings templates are auto-fetched from the
database when sending: {name} {user_id} {email} {PEPP course}
{academic_year} {access_end} {paid_amount} {total_fee} {collected}
{balance} and any users-table column as {column_name}.

DEPLOY ORDER FOR THIS UPDATE
----------------------------
1. Run database-update-3.sql once in phpMyAdmin.
2. Upload all PHP files (incl. includes/) + pepp-logo.jpg to site root.
3. Settings → Invoice Settings: confirm GST account + series dates.
4. Payments → Invoices → "Generate missing invoices" (one time).

HOTFIX — REPORTS PAGE 500 ERROR + EMAIL DOMAIN
----------------------------------------------
• reports.php no longer hard-crashes if includes/invoice_helper.php is
  missing on the server — but the invoice include files ARE required for
  invoicing, so upload the ENTIRE includes/ folder.
• All PHP 7.4-only "fn()" arrow functions were rewritten as classic
  closures — the admin now runs on PHP 7.2+ as well.
• Invoice emails now send From payments@pepplearning.in
  (Reply-To noreply@pepplearning.in). Create the
  payments@pepplearning.in mailbox in Hostinger → Emails.
• NEW system-check.php: if any page shows HTTP 500, upload and open
  yourdomain/admissions/system-check.php — it reports the PHP version,
  any missing files, extensions and database tables. Delete it after use.

UPDATE 5 — REVERT APPROVAL · PDF PHOTO ICON · LEAD MANAGEMENT (CRM)
------------------------------------------------------------------
New files:
  lead-management.php    CRM dashboard, filters, add lead, bulk CSV import
  lead-details.php       single lead: timeline, remarks, follow-ups, convert
  lead-sample.csv        sample import file
  database-update-4.sql  ★ RUN ONCE in phpMyAdmin (after updates 1-3)
Updated: includes/auth.php (photo helper + 'leads' page key),
includes/admin_nav.php (CRM section + due-today badge),
student-details.php (revert-to-pending + PDF photo icon), system-check.php.

1) REVERT APPROVED STUDENT TO PENDING  (Super Admin only)
   Student profile → Danger Zone → "Revert to Pending". Undoes the
   approval and ALL approval-time data: student status, onboarding
   records, generated installments, course access dates, fee/plan, and
   any invoices — then moves the student back to the Approvals (pending)
   list. The registration details and registration payment are kept so
   you can review and re-approve. The reversal is logged. Regular admins
   do not see this button and the action is blocked server-side for them.

2) PDF UPLOADED WHERE A PHOTO WAS EXPECTED
   If a student uploaded a PDF (or any non-image) as their photo, the
   profile now shows a red PDF file icon (click to open) instead of a
   broken image. Same treatment for a PDF registration receipt.

3) LEAD MANAGEMENT (CRM)            permission key: 'leads'
   • Grant the "Lead Management" page to specific admins in Admin
     Management (Super Admin). Each admin sees only the leads assigned to
     them; the Super Admin sees and filters everyone's.
   • Add individual leads or bulk-import a CSV (Excel → Save As → CSV).
     Fields: WhatsApp number (required), name, interested PEPP course,
     last institute, last course, FYUGP (yes/no), year of study, status,
     next follow-up date, remarks.
   • Pipeline statuses: New, Contacted, Follow-up, Interested,
     Not Interested, Converted, Rejected. A next follow-up date is
     required until a lead is Converted or Rejected.
   • Each lead has a full activity timeline — every remark, status change,
     follow-up (counted) and reassignment with the admin name and exact
     date/time. Convert a lead (auto-matches an existing student by phone)
     or, for the Super Admin, delete it.
   • The dashboard surfaces TODAY'S and OVERDUE follow-ups at the top for
     one-click action, with stat cards (Total, Due Today, Overdue,
     Converted) and filters by status, follow-up timing, assigned admin,
     course and free-text search. The sidebar shows a live count of leads
     due today/overdue.

DEPLOY ORDER FOR THIS UPDATE
----------------------------
1. Run database-update-4.sql once in phpMyAdmin.
2. Upload all PHP files (incl. includes/) + lead-sample.csv.
3. Super Admin → Admin Management: grant "Lead Management" to the admins
   who handle leads. They get 20-min auto-logout like every other admin.

UPDATE 6 — NAV REORDER · REPORTS FIX · ADMIN CONTACTS · ACCESS LOCKDOWN
        · LEAD "ALL ADMINS" · REMINDERS
----------------------------------------------------------------------
New files:
  reminders-action.php          add / complete / dismiss / postpone endpoint
  includes/reminders_helper.php due detection + no-reply email
  database-update-5.sql         ★ RUN ONCE (after updates 1-4)
Updated: includes/admin_nav.php (CRM moved up + reminder bell),
includes/admin_footer.php (reminder modal + urgent alert),
assets/css/admin-theme.css (bell + urgent animations),
reports.php, admin-management.php, settings.php,
lead-management.php, lead-details.php, system-check.php.

1) NAVIGATION: the CRM (Lead Management) section now sits directly after
   Students, before Payments.

2) REPORTS FIX: reports.php referenced a non-existent column
   (course_duration_date). It now reads course_expiry_date /
   course_end_date, and any report error shows the real database message
   to the Super Admin instead of a generic "Could not load".

3) ADMIN CONTACTS: Admin Management now has Email and Phone fields when
   creating an admin, and both are editable any time from the "Edit
   access & details" (key) button. Email is used for reminder
   notifications.

4) ACCESS LOCKDOWN:
   • Non-super admins now see ONLY their own "Admin Account" (password
     change) panel in Settings — all other settings panels are hidden and
     blocked server-side. Every other admin feature stays Super-Admin only.
   • The Super Admin can reset ANY admin's password from Admin Management
     WITHOUT entering the existing password (the key/Reset Password
     button), at any time.
   • Admins still change their own password in Settings using their
     current password.

5) LEAD MANAGEMENT: the "Assign To" dropdown (add lead, bulk import and
   the lead detail page) now includes "All Admins". Leads assigned to All
   Admins are visible to every admin. The current admin remains the
   default selection.

6) REMINDERS (top-bar bell on every page):
   • Any admin can add a reminder/task with a date & time and assign it to
     themselves or to "All Admins" (current admin is the default).
   • A bell button in the top bar shows the pending count; when a reminder
     is due it shakes red.
   • When the scheduled time arrives the assignee sees a full-screen URGENT
     animated alert (pulsing red) with Done / Snooze 1h / Dismiss actions,
     and receives a one-time no-reply email at their registered address
     (noreply@pepplearning.in) — so add admin emails in Admin Management.
   • From the bell modal or the urgent alert, admins can mark complete,
     postpone to a new date/time, or dismiss. All actions are logged.

DEPLOY ORDER FOR THIS UPDATE
----------------------------
1. Run database-update-5.sql once in phpMyAdmin.
2. Upload all changed PHP files, includes/, assets/css/admin-theme.css,
   and reminders-action.php.
3. Admin Management: add email addresses for admins who should get
   reminder emails.

UPDATE 6b — COLLATION FIX + REMINDER VISIBILITY
-----------------------------------------------
New file: database-update-6.sql  ★ RUN ONCE (after update 5)
          reminders-check.php    diagnostic (open once, then delete)
Updated:  reports.php (Admin Activity tab), reminders-action.php,
          includes/admin_footer.php, database-update-2/3/4/5.sql.

1) REPORTS "Illegal mix of collations for operation 'UNION'":
   The Admin Activity report combined two tables created with different
   collations. reports.php now merges the two sources in PHP (no
   cross-table UNION), so the tab loads. database-update-6.sql also
   normalises every new table to utf8mb4_unicode_ci to match the
   originals — run it once to keep the database consistent.

2) REMINDERS NOT VISIBLE:
   • Added on-screen confirmation: after add/complete/postpone/dismiss a
     green (or red) toast appears, so you can see the action registered.
   • datetime-local values are now normalised before saving.
   • A reminder is visible to an admin only if it is assigned to THEM or
     to "All Admins". If you assigned it to a different admin it won't show
     for you — open reminders-check.php (Super Admin) to list every
     reminder, who it's assigned to, and what is visible to you.

DEPLOY: run database-update-6.sql once, upload reports.php,
reminders-action.php, includes/admin_footer.php (+ reminders-check.php to
diagnose). Then open reports.php?tab=activity — it loads now.

UPDATE 7 — EMERGENCY REMINDER POPUP · FACULTIES · SESSIONS · ACCOUNTS
--------------------------------------------------------------------
New files:
  faculties.php / faculty-report.php   faculty management + statement PDF/email
  sessions.php                         class/webinar scheduling + notifications
  accounts.php                         expenses & per-account balances
  includes/session_mailer.php          learner notification emails
  includes/session_cron.php            automatic 12h/4h/10m/start reminders
  database-update-7.sql                ★ RUN ONCE (after updates 1-6)
Updated: includes/auth.php (3 new page keys), includes/admin_nav.php
(new nav items + session dispatcher), includes/admin_footer.php (emergency
popup), reminders-action.php (skip-5-min), includes/reminders_helper.php
(snooze), settings.php (Expense Types), assets/css/admin-theme.css,
system-check.php.

1) EMERGENCY REMINDER POPUP
   When a reminder's time arrives, a full-screen RED emergency popup appears
   on whatever page the admin is on, with a sweeping siren light, blinking
   "URGENT TASK" badge, pulsing icon and a repeating attention beep
   (WebAudio; resumes on first click if the browser blocks autoplay).
   Buttons: Completed · Skip 5 min · Postpone · Dismiss. Multiple due
   reminders are shown ONE BY ONE ("Task 1 of N") — acting on one shows the
   next. If the admin was offline when it was due, it appears at next
   sign-in. Emails still go to the assignee's address.

2) FACULTIES  (Academics → Faculties, page key 'faculties')
   Add faculty with mobile, email, PEPP academic year, active/inactive and
   per-session-type hourly charges (Live / QPD / Recorded / Offline). Each
   faculty page shows completed vs pending schedules, total earned (auto-
   calculated from completed sessions × their hourly rate by type), paid,
   and payment pending. Record a payment from a payment account, and
   generate / download / email a statement PDF to the faculty.

3) SESSIONS  (Students → Sessions, page key 'sessions')
   Schedule a session with topic, faculty, date/time, duration, type, meet
   link (live) or venue (offline) and one or more courses. Upcoming /
   ongoing / completed views. "Notify learners" sends a manual reminder per
   session. For LIVE and OFFLINE sessions, approved learners of the selected
   course(s) are emailed automatically 12h, 4h, 10m before and at start
   (live emails include a Join button). Auto-reminders are dispatched when
   any admin loads a page (no server cron needed); each window sends once.

4) ACCOUNTS & EXPENSES  (CRM → Accounts, page key 'accounts')
   Record expenses (purpose, type, amount, remarks, payment account, date).
   Expense types are managed in Settings → Expense Types (seeded from your
   file). Faculty payments appear automatically as outgoings. Shows revenue
   in / expenses / faculty paid / balance per account, overall net balance,
   filters (type, account, dates, search) and CSV export. Grant 'accounts'
   to admins in Admin Management.

DEPLOY ORDER FOR THIS UPDATE
----------------------------
1. Run database-update-7.sql once in phpMyAdmin.
2. Upload all PHP files, includes/, assets/css/admin-theme.css.
3. Admin Management: grant Faculties / Sessions / Accounts pages to admins.
4. Confirm noreply@pepplearning.in mailbox exists (session & statement mail).

UPDATE 8 — GOOGLE SIGN-IN · ALUMNI DB · ALUMNI PORTAL · REFERRAL PROGRAM
        · DISCOUNT COUPONS · REGISTER FEE/COUPON
------------------------------------------------------------------------
New files:
  config/google_oauth.php        Google OAuth config + helpers (shared)
  google-callback.php            Google sign-in (admin + alumni branches)
  alumni-database.php            Super Admin: add/import alumni
  alumni-sample.csv              import template
  alumni-portal.php              PUBLIC PEPPian portal (register/verify/referral)
  marketing.php                  CRM → Marketing (referral program + coupons)
  includes/referral_helper.php   coupon/referral validation + crediting
  database-update-8.sql          ★ RUN ONCE (after updates 1-7)
Updated: login.php (Google button), admin-management.php (Google email),
includes/auth.php ('marketing' key), includes/admin_nav.php (Marketing nav),
includes/admin_footer.php (popup render fix), settings.php (Alumni link),
register.php (auto fee + coupon), student-approval.php & studentonboarding.php
(referral crediting hooks), system-check.php.

1) ADMIN GOOGLE SIGN-IN: a "Sign in with Google" button sits above the
   username/password form on login.php. Only admins whose Email or Google
   sign-in email (set in Admin Management) matches the Google account, and
   who are active, can sign in. No self-registration.
   ★ In Google Cloud console add this authorized redirect URI:
     https://pepplearning.in/admissions/google-callback.php

2) ALUMNI DATABASE (Settings → Alumni Database, Super Admin): add an
   alumnus or bulk-import a CSV (sample provided). Academic-year dropdown
   shows only INACTIVE (past) batches. Course name is free text. If an
   imported/added mobile or email matches an existing alumnus, it's folded
   into that alumnus's secondary mobile/email.

3) REMINDER POPUP FIX: the emergency popup now builds its buttons with DOM
   methods (no fragile inline quotes) and forces the overlay visible, so it
   always appears for the assigned admin while the alert sounds.

4) MARKETING → REFERRAL PROGRAM: configure per ACTIVE academic year — user
   discount, alumni earning per referral, toggles (once-per-user; partial
   50/50 crediting), editable referee T&C (sensible defaults provided),
   referral-ID prefix + start sequence, start/end dates. See all referees
   with live earning wallets, record manual payouts with a proof upload &
   credit date, and view analytics (users joined, credited, paid, user
   benefits) with Excel export.

5) ALUMNI PORTAL (public: /admissions/alumni-portal.php): PEPPian sign-up
   (email/password or Google; Google users add WhatsApp next) → alumni
   verification (enter any PEPP email/mobile; auto-matched against the
   alumni DB incl. secondary fields; active batches blocked) → dashboard.
   Verified alumni ("PEPPians") can apply to an active referral program
   (payout details + accept T&C + a short loading animation), receive a
   unique referral code (admin prefix + sequence), a shareable register
   link with the code prefilled, a downloadable referral coupon image, and
   live earnings/credits/balance/pending. Earnings credit when the referred
   student is approved AND onboarded (partial 50/50 honored).

6) REGISTER.PHP: the Payment Information section now auto-shows the course
   fee from the chosen PEPP course + year, with an "Add Coupon / Referral
   Code" box that validates live and shows discount + total payable. A
   referral link (?ref=CODE) prefills the code automatically.

7) MARKETING → DISCOUNT COUPONS: full coupon dashboard — flat or percent
   (with max cap), scope by year/course, total usage limit, per-user-once,
   start/end dates, activate/deactivate, redemption tracking. Coupons apply
   at registration alongside referral codes.

DEPLOY ORDER FOR THIS UPDATE
----------------------------
1. Run database-update-8.sql once in phpMyAdmin.
2. Upload all new/changed files (keep folder structure). Ensure the folder
   uploads/payouts/ is writable (created automatically if PHP can write).
3. Google Cloud console → add redirect URI (see #1 above).
4. Admin Management: set each admin's Google sign-in email if different
   from their email.
5. Confirm noreply@pepplearning.in mailbox exists.
6. Grant 'marketing' page to admins who manage referrals/coupons.

UPDATE 8b — ALUMNI PORTAL PREMIUM UI · ALUMNI GOOGLE CLIENT · BULK IMPORT FIX
----------------------------------------------------------------------------
Updated: alumni-portal.php (premium redesign), config/google_oauth.php
(separate alumni Google client), google-callback.php (purpose-aware client
+ routing), alumni-database.php (robust bulk import).

1) ALUMNI PORTAL UI: redesigned to a premium look — Plus Jakarta Sans type,
   gradient plum/gold hero with perks, glass top bar, elevated cards,
   refined stats, and a gradient referral coupon that matches the printed
   card (gift icon, name, phone, dashed code, expiry).

2) ALUMNI GOOGLE SIGN-IN now uses its own OAuth client
   (373139526353-...apps.googleusercontent.com), separate from the admin
   login client. Admin login keeps its original client.
   ★ In the Google Cloud console for the ALUMNI client, add redirect URI:
     https://pepplearning.in/admissions/google-callback.php

3) BULK IMPORT FIX: the alumni CSV import is rewritten to handle large files
   (6,000+ rows) reliably — batched DB transactions (commit every 500 rows),
   each row wrapped so one bad row is skipped (not the whole import),
   UTF-8 BOM + Windows/Mac line-ending handling, longer time/memory limits,
   field-length capping, and more header aliases. The provided 6,272-row
   file imports as ~5,778 added + ~494 in-file duplicates merged, 0 errors.
   If a real DB error ever occurs, the exact message is now shown (not a
   generic one) and any open transaction is rolled back.

DEPLOY: upload alumni-portal.php, config/google_oauth.php,
google-callback.php, alumni-database.php. No new SQL needed (uses the
update-8 tables). Register the alumni Google client's redirect URI.

UPDATE 8c — ALUMNI GOOGLE CALLBACK URI FIX · VERIFICATION FIX · SUPPORT
----------------------------------------------------------------------
New file: alumni-google-callback.php
Updated:  alumni-portal.php (verification rewrite + WhatsApp support).

1) GOOGLE SIGN-IN FIX: the alumni OAuth client registers the redirect URI
   .../admissions/alumni-google-callback.php, but the code used
   google-callback.php — Google rejects URI mismatches. Added a dedicated
   alumni-google-callback.php that exactly matches the registered URI, and
   pointed the portal's Google button at it. Admin login is unaffected.
   ★ Confirm in Google Cloud (alumni client) the Authorized redirect URI is:
     https://pepplearning.in/admissions/alumni-google-callback.php

2) VERIFICATION FIX: rewrote the alumni verification. Phone input now
   normalises +91 / 12-digit / 0-prefixed / spaced / dashed numbers to the
   last 10 digits and matches against BOTH mobile and secondary_mobile;
   email matches against BOTH email and secondary_email. Matching happens in
   PHP (not fragile SQL), wrapped in its own try/catch, so it no longer
   throws "Something went wrong" and gives clear, specific messages.

3) CONTACT SUPPORT: the verification page now has a "Contact Support
   (PEPP Admin Desk)" WhatsApp button to +91 95672 76458 with a prefilled
   message containing the alumnus's portal email/name, so the team can help
   verify quickly.

DEPLOY: upload alumni-google-callback.php and alumni-portal.php. No SQL
change. Ensure the alumni client's redirect URI matches (see #1).

UPDATE 9 — COLLATION FIX · PORTAL LOGIN/PHONE FIX · REFERRAL PROGRAM RULES
-------------------------------------------------------------------------
New file: database-update-9.sql  ★ RUN ONCE (after update 8)
Updated:  config/database.php, alumni-database.php, alumni-portal.php,
          alumni-google-callback.php, google-callback.php, marketing.php.

1) "ILLEGAL MIX OF COLLATIONS" WHEN ADDING ALUMNI: the alumni table was on
   a different collation than the connection parameters. Fixes:
   • config/database.php now sets the connection to utf8mb4_unicode_ci.
   • database-update-9.sql converts alumni/peppians/referral/coupon tables
     to utf8mb4_unicode_ci.
   • All alumni & portal lookups now match in PHP (LIKE prefilter + confirm)
     instead of cross-collation SQL, so they work even before the migration.

2) ALUMNI PORTAL LOGIN / PHONE: fixed a duplicated code branch that broke
   the POST handler, made email lookups (register, login, Google) collation-
   safe, and confirmed the Google → "complete profile" → WhatsApp save path.
   Google sign-up, password sign-in and the WhatsApp save now work reliably.

3) REFERRAL PROGRAM EDITS ARE FORWARD-ONLY: editing Referral Discount or
   Alumni Earning changes only FUTURE referrals. Amounts already earned by
   referees and discounts already given to users are frozen
   (referral_earnings.full_amount + coupon_redemptions) and never rewritten.

4) ONE ACTIVE PROGRAM AT A TIME: you cannot activate a new program while
   another year's program is active — deactivate the active one first. The
   Marketing page shows which program is active.

5) "APPLY & GET MY REFERRAL CODE": fixed (the duplicated branch in #2 was
   the cause). Verified PEPPians can now apply, get a unique referral code,
   shareable link and downloadable coupon smoothly.

DEPLOY: run database-update-9.sql once, then upload config/database.php,
alumni-database.php, alumni-portal.php, alumni-google-callback.php,
google-callback.php, marketing.php.
