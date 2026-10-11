<?php
/**
 * Centralised, deny-by-default role-based access control.
 * The ONLY place that maps roles to permissions. Role comes from users.json on the server.
 * Technical administration (sysadmin) is deliberately separated from official legislative
 * approval: sysadmin has NO minutes.approve / agenda.finalize / attendance.record etc.
 */
const SSMS_ROLES = [
    'sysadmin'      => 'System Administrator',
    'secretary'     => 'Legislative Secretary / Secretariat Staff',
    'presiding'     => 'Presiding Officer',
    'councilor'     => 'Council Member / Councilor',
    'documentation' => 'Documentation Staff / Transcriptionist',
    'viewer'        => 'Authorized Viewer / Observer',
];

const SSMS_PERMISSIONS = [
    'sysadmin' => [
        'users.manage', 'members.manage', 'devices.manage', 'audit.view', 'settings.manage', 'mfa.reset.request', 'mfa.reset.approve',
        'schedule.view',
    ],
    'secretary' => [
        'members.manage',
        'schedule.view', 'schedule.manage', 'schedule.cancel',
        'agenda.view', 'agenda.edit', 'agenda.finalize', 'agenda.revise',
        'attendance.view_all', 'attendance.record', 'attendance.correct', 'quorum.view',
        'proceedings.view', 'proceedings.draft', 'proceedings.review', 'proceedings.amend', 'proceedings.view_draft',
        'minutes.view_draft', 'minutes.generate', 'minutes.edit', 'minutes.submit', 'minutes.publish', 'minutes.view_approved',
        'tracking.view', 'tracking.update_current',
    ],
    'presiding' => [
        'schedule.view', 'agenda.view', 'attendance.view_all', 'quorum.view',
        'proceedings.view', 'proceedings.review', 'proceedings.view_draft',
        'minutes.view_draft', 'minutes.approve', 'minutes.view_approved',
        'tracking.view', 'session.transition',
    ],
    'councilor' => [
        'schedule.view', 'agenda.view', 'attendance.view_own', 'quorum.view',
        'proceedings.view', 'minutes.view_approved', 'tracking.view',
    ],
    'documentation' => [
        'schedule.view', 'agenda.view', 'proceedings.view', 'proceedings.view_draft', 'proceedings.draft',
        'minutes.view_draft', 'tracking.view',
    ],
    'viewer' => [
        'schedule.view', 'agenda.view_final', 'minutes.view_published',
    ],
];

function ssms_role_label(string $role): string { return SSMS_ROLES[$role] ?? 'Unknown role'; }
function ssms_valid_role(string $role): bool { return isset(SSMS_ROLES[$role]); }

function ssms_can(?array $user, string $perm): bool {
    if (!$user || empty($user['active']) || !empty($user['locked'])) return false;
    $list = SSMS_PERMISSIONS[$user['role'] ?? ''] ?? [];         // unknown role => no permissions
    if (in_array($perm, $list, true)) return true;
    return false;
}
function ssms_can_any(?array $user, array $perms): bool {
    foreach ($perms as $p) if (ssms_can($user, $p)) return true;
    return false;
}

/** Sidebar / dashboard module catalogue; visible when the user has ANY listed permission. */
function ssms_modules(): array {
    return [
        'members'     => ['icon' => 'fa-solid fa-users',           'label' => 'Council Members',           'file' => 'modules/members.php',     'perm' => ['members.manage']],
        'scheduling'  => ['icon' => 'fa-solid fa-calendar-check',  'label' => 'Session Scheduling',        'file' => 'modules/scheduling.php',  'perm' => ['schedule.view']],
        'agenda'      => ['icon' => 'fa-solid fa-list-check',      'label' => 'Agenda Preparation',        'file' => 'modules/agenda.php',      'perm' => ['agenda.view', 'agenda.view_final']],
        'attendance'  => ['icon' => 'fa-solid fa-user-check',      'label' => 'Attendance & Quorum',       'file' => 'modules/attendance.php',  'perm' => ['attendance.view_all', 'attendance.view_own', 'quorum.view']],
        'proceedings' => ['icon' => 'fa-solid fa-file-lines',      'label' => 'Proceedings Documentation', 'file' => 'modules/proceedings.php', 'perm' => ['proceedings.view', 'proceedings.draft']],
        'minutes'     => ['icon' => 'fa-solid fa-file-signature',  'label' => 'Minutes Generation',        'file' => 'modules/minutes.php',     'perm' => ['minutes.view_draft', 'minutes.view_approved', 'minutes.view_published']],
        'tracking'    => ['icon' => 'fa-solid fa-tower-broadcast', 'label' => 'Real-Time Tracking',        'file' => 'modules/tracking.php',    'perm' => ['tracking.view']],
    ];
}
function ssms_admin_modules(): array {
    return [
        'admin_users'   => ['icon' => 'fa-solid fa-users-gear',   'label' => 'User Accounts',    'file' => 'admin_users.php',    'perm' => ['users.manage']],
        'devices'       => ['icon' => 'fa-solid fa-qrcode',       'label' => 'QR Badges & Devices', 'file' => 'manage_devices.php', 'perm' => ['devices.manage']],
        'audit'         => ['icon' => 'fa-solid fa-clipboard-list','label' => 'Audit Log',        'file' => 'audit_view.php',     'perm' => ['audit.view']],
        'settings'      => ['icon' => 'fa-solid fa-sliders',      'label' => 'System Settings',  'file' => 'settings.php',       'perm' => ['settings.manage']],
    ];
}
function ssms_home_for(array $user): string {
    if (ssms_can($user, 'users.manage')) return 'dashboard.php';
    return 'dashboard.php';
}
