# User Guide — Legislative Services Management System

This guide covers how to actually use the system, for both staff and council members.

## Logging In

### If you're staff (Secretary or Admin)

Go to `login.php`, enter your username and password. A few things to know:

- **5 wrong attempts locks you out for 10 minutes** — the countdown shown is accurate even if you reload the page, since it's tracked server-side, not just in your browser.
- **After 15 minutes of inactivity**, a "Still there?" warning pops up with a 5-minute countdown. Click **Stay signed in** to continue, or you'll be automatically logged out at the 20-minute mark.
- If you forgot your password, ask another Admin to reset it for you from **Account Settings** — no email/SMS reset exists, so this is the only recovery path. If there's only one staff account in the whole system and that person is locked out, recovery requires direct database access.

### If you're a council member

Go to `qr_login.php` on your own phone and scan your personal QR badge (printed, or shown on someone else's screen — not your own phone's screen, since you can't scan your own device). A few things to know:

- **You must be physically at the configured location** — your phone's real GPS location is checked against the office coordinates every single time you log in, not just the first time.
- **The first device that scans your badge is permanently bound to it.** If you get a new phone, ask the Secretary's Office to reset your device binding in Manage Devices.
- **If a session is currently Ongoing when you scan**, you're automatically marked Present — no separate check-in step needed.

---

## For Staff: Running a Session

### 1. Schedule the session

Go to **Session Scheduling** → fill in title, type, date, time, venue → **Schedule**. It starts as "Scheduled."

### 2. Start the session

When it's actually time to begin, change its status to **Ongoing**. This matters — several things are locked until the session is Ongoing:

- You can't mark attendance for a session that's still "Scheduled."
- You can't log proceedings for a session that's still "Scheduled."
- You can't generate minutes for a session that's still "Scheduled."

This is intentional — it stops the system from recording things (who attended, what was said, official minutes) for a meeting that technically hasn't happened yet.

### 3. Build the agenda

Go to **Agenda Preparation**, add items, and track their status (Pending → Discussed → Approved/Deferred) as the session moves along.

### 4. Track attendance

Go to **Attendance & Quorum**. Mark each council member Present/Excused/Absent by hand, or let council members self-check-in by scanning their own QR badge (this happens automatically the moment they log in, if the session is Ongoing).

### 5. Log proceedings as things happen

Go to **Proceedings Documentation**. You have two ways to add an entry:

- **Type manually** — just type into the note field.
- **Voice dictation** — pick your spoken language (English, Tagalog/Filipino, or the approximate Taglish option — see note below), optionally pick a specific microphone if your device has more than one, click **Record**, and speak. It transcribes live as you talk. Click **Stop & Transcribe** when done, review the text, then submit.

> **A note on Taglish**: no browser actually has a dedicated "Taglish" speech recognition mode — that's not a real, distinct engine that exists anywhere. The Taglish option in the dropdown uses the Filipino recognition engine underneath, which tends to handle common English words mixed into Tagalog speech reasonably well, but it's an approximation, not a perfect dedicated mode.

> **A note on microphone selection**: the browser's speech recognition engine doesn't have an official way to be told "use this specific microphone" — it normally just uses whatever your system's default input is. The microphone dropdown here uses a common workaround that works on current Chrome/Edge, but it isn't a guaranteed, official browser feature.

### 6. Generate minutes

Once the session is Ongoing or Completed, go to **Minutes Generation**, pick the session, and click **Generate Minutes**. It automatically pulls in attendance, agenda, and proceedings — you don't retype anything. The document shows who generated it and when.

### 7. Complete and archive

Mark the session **Completed** (or **Cancelled** if it didn't happen), then archive it from Session Scheduling. Archived sessions move to **Session Archive**, where you can view the full official record or export it as JSON.

---

## For Staff: Account Management

Go to **Account Settings**.

- **Change your own password** — anyone can do this, requires your current password.
- **Admin only**: add new staff accounts (choosing Secretary or Admin role), remove accounts, promote/demote roles, and reset a locked-out colleague's password (generates a one-time temporary password shown once on screen — give it to them directly).
- **The system always keeps at least one Admin** — you can't demote or remove the last remaining Admin account, so the system can never end up with nobody able to manage staff accounts.

---

## For Staff: Managing Council Members & QR Badges

- **Council Members**: add, edit, or remove council members from the roster.
- **Manage QR Badges & Devices**:
  - **Generate a badge** for a member, then either print it, display it on your own screen for them to scan, or hand it over some other way — just not by sending the image to the same phone they'll scan with.
  - **Reset Device** if a member gets a new phone — the same physical badge still works, the next scan binds the new device.
  - **Regenerate Token** if a phone was **stolen** — this invalidates the old badge entirely (not just the device binding), which matters if the badge image was ever sent digitally and might still exist on the stolen phone.

---

## For the Public

No login needed — go to the public dashboard URL. You'll see the currently featured session's status, agenda, and live quorum count, refreshing automatically. Nothing sensitive (council member names, proceedings text, minutes) is shown here — only what's already meant to be public.

---

## Troubleshooting

- **"Too many failed attempts" on staff login** — wait out the 10-minute lockout, or ask an Admin to check if you have the right username.
- **QR scan fails with a location error** — you're not within the allowed distance of the configured office location. This is checked every time, not just once.
- **QR scan says "already registered to another device"** — that badge is bound to a different phone. Ask the Secretary's Office to Reset Device or Regenerate Token in Manage Devices.
- **Can't mark attendance / log proceedings / generate minutes** — check the session's status in Session Scheduling. It must be "Ongoing" or "Completed" — these actions are blocked for "Scheduled" or "Cancelled" sessions on purpose.
- **Voice dictation doesn't respond** — make sure you're using Chrome or Edge, that you allowed microphone access when prompted, and that you have an internet connection (the browser's speech engine needs one).
- **Signed out unexpectedly** — if you were inactive for 20 minutes, this is the AFK auto-logout working as intended. Just log back in.
- **Dark mode text looks wrong somewhere** — Minutes and Archive documents are deliberately kept in a fixed light "paper" appearance regardless of your theme setting, since official documents shouldn't visually change based on personal preference.
