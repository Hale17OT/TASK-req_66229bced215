<?php
namespace app\command;

use app\service\auth\PasswordPolicy;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\facade\Db;

/**
 * `php think db:seed`
 *
 * Idempotent: re-running does not duplicate data. Inserts permissions, baseline
 * roles, role↔permission grants per the §11.2 matrix, default admin + one demo
 * user per role, sample locations and departments.
 */
class DbSeed extends Command
{
    protected function configure(): void
    {
        $this->setName('db:seed')->setDescription('Seed roles, permissions, demo users, locations, departments');
    }

    protected function execute(Input $input, Output $output): int
    {
        $this->seedPermissions($output);
        $this->seedRoles($output);
        $this->seedRolePermissions($output);
        $this->seedLocationsDepartments($output);
        $this->seedUsers($output);
        $output->writeln('<info>db:seed complete</info>');
        return 0;
    }

    private function seedPermissions(Output $out): void
    {
        $perms = [
            // category, key, description
            ['auth',          'auth.manage_users',           'Create/edit/lock/disable users'],
            ['auth',          'auth.manage_roles',           'Create/edit/delete roles and grants'],
            ['auth',          'auth.manage_permissions',     'Inspect raw permission catalog'],
            ['auth',          'auth.view_sessions',          'View other users\' sessions'],
            ['auth',          'auth.revoke_sessions',        'Revoke other users\' sessions'],
            ['attendance',    'attendance.record',           'Record attendance'],
            ['attendance',    'attendance.request_correction','Submit attendance correction request'],
            ['attendance',    'attendance.review_correction','Review attendance correction request'],
            ['schedule',      'schedule.view_assigned',      'View own coach schedule'],
            ['schedule',      'schedule.request_adjustment', 'Submit schedule adjustment'],
            ['schedule',      'schedule.review_adjustment',  'Review schedule adjustment'],
            ['budget',        'budget.view',                 'View budget utilization'],
            ['budget',        'budget.manage_categories',    'Manage budget categories'],
            ['budget',        'budget.manage_allocations',   'Manage monthly allocations'],
            ['funds',         'funds.view_commitments',      'View active commitments'],
            ['reimbursement', 'reimbursement.create',        'Create reimbursement draft'],
            ['reimbursement', 'reimbursement.edit_own_draft','Edit own draft'],
            ['reimbursement', 'reimbursement.submit',        'Submit reimbursement'],
            ['reimbursement', 'reimbursement.review',        'Review reimbursement'],
            ['reimbursement', 'reimbursement.approve',       'Approve reimbursement'],
            ['reimbursement', 'reimbursement.reject',        'Reject reimbursement'],
            ['reimbursement', 'reimbursement.override_cap',  'Admin override of over-cap approvals'],
            ['settlement',    'settlement.record',           'Record settlement'],
            ['settlement',    'settlement.confirm',          'Confirm settlement'],
            ['settlement',    'settlement.refund',           'Issue refund'],
            ['ledger',        'ledger.view',                 'View ledger'],
            ['audit',         'audit.view',                  'Search audit logs'],
            ['audit',         'audit.export',                'Export audit/finance CSVs'],
            ['sensitive',     'sensitive.unmask',            'View masked sensitive fields'],
            ['dashboard',     'dashboard.view_role_specific','View role-specific dashboard'],
        ];
        foreach ($perms as [$cat, $key, $desc]) {
            Db::execute(
                "INSERT INTO permissions (`key`, description, category) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE description = VALUES(description), category = VALUES(category)",
                [$key, $desc, $cat]
            );
        }
        $out->writeln(sprintf('<info>permissions: %d</info>', count($perms)));
    }

    private function seedRoles(Output $out): void
    {
        $roles = [
            ['Administrator', 'Administrator', 'Full governance and override authority', 1],
            ['FrontDesk',     'Front Desk',    'Attendance recording and correction requests', 1],
            ['Coach',         'Coach',         'Schedule viewing and adjustment requests', 1],
            ['Finance',       'Finance',       'Budget setup, settlement entry, reconciliation', 1],
            ['Operations',    'Operations',    'Approval/rejection of reimbursement workflows', 1],
        ];
        foreach ($roles as [$key, $name, $desc, $sys]) {
            Db::execute(
                "INSERT INTO roles (`key`, name, description, is_system) VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description), is_system = VALUES(is_system)",
                [$key, $name, $desc, $sys]
            );
        }
        $out->writeln(sprintf('<info>roles: %d</info>', count($roles)));
    }

    private function seedRolePermissions(Output $out): void
    {
        // Per spec §11.2 baseline matrix
        $matrix = [
            'Administrator' => ['*'], // all
            'FrontDesk' => [
                'dashboard.view_role_specific',
                'attendance.record',
                'attendance.request_correction',
                'reimbursement.create', 'reimbursement.edit_own_draft', 'reimbursement.submit',
            ],
            'Coach' => [
                'dashboard.view_role_specific',
                'schedule.view_assigned', 'schedule.request_adjustment',
                'reimbursement.create', 'reimbursement.edit_own_draft', 'reimbursement.submit',
            ],
            'Finance' => [
                'dashboard.view_role_specific',
                'budget.view', 'budget.manage_categories', 'budget.manage_allocations',
                'funds.view_commitments',
                'reimbursement.create', 'reimbursement.edit_own_draft', 'reimbursement.submit',
                'reimbursement.review',
                'settlement.record', 'settlement.confirm', 'settlement.refund',
                'ledger.view', 'audit.view', 'audit.export',
            ],
            'Operations' => [
                'dashboard.view_role_specific',
                'attendance.review_correction',
                'schedule.review_adjustment',
                'reimbursement.review', 'reimbursement.approve', 'reimbursement.reject',
                'audit.view', 'audit.export',
            ],
        ];
        $allPermIds = Db::table('permissions')->column('id', 'key');
        foreach ($matrix as $roleKey => $perms) {
            $roleId = (int)Db::table('roles')->where('key', $roleKey)->value('id');
            if (!$roleId) continue;
            $permIds = $perms === ['*'] ? array_values($allPermIds) : array_values(array_filter(array_map(fn($k) => $allPermIds[$k] ?? null, $perms)));
            Db::table('role_permissions')->where('role_id', $roleId)->delete();
            foreach (array_unique($permIds) as $pid) {
                Db::execute("INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)", [$roleId, $pid]);
            }
            $out->writeln(sprintf('<info>role %s ← %d perms</info>', $roleKey, count($permIds)));
        }
    }

    private function seedLocationsDepartments(Output $out): void
    {
        $locs = [['HQ', 'Headquarters'], ['NORTH', 'North Studio'], ['SOUTH', 'South Studio']];
        foreach ($locs as [$code, $name]) {
            Db::execute("INSERT INTO locations (code, name) VALUES (?, ?) ON DUPLICATE KEY UPDATE name = VALUES(name)", [$code, $name]);
        }
        $deps = [['OPS', 'Operations'], ['FIN', 'Finance'], ['COACH', 'Coaching']];
        foreach ($deps as [$code, $name]) {
            Db::execute("INSERT INTO departments (code, name) VALUES (?, ?) ON DUPLICATE KEY UPDATE name = VALUES(name)", [$code, $name]);
        }
        $out->writeln('<info>locations + departments seeded</info>');
    }

    private function seedUsers(Output $out): void
    {
        $policy = PasswordPolicy::fromConfig();
        $tempHash = $policy->hash('Admin!Pass#2026');
        $users = [
            ['admin',     'Administrator',     'Administrator', true],
            ['frontdesk', 'Front Desk Demo',   'FrontDesk',     false],
            ['coach',     'Coach Demo',        'Coach',         false],
            ['finance',   'Finance Demo',      'Finance',       false],
            ['operations','Operations Demo',   'Operations',    false],
        ];
        $hqId = (int)Db::table('locations')->where('code', 'HQ')->value('id');
        foreach ($users as [$username, $display, $roleKey, $isAdmin]) {
            $exists = Db::table('users')->where('username', $username)->find();
            if ($exists) { $out->writeln("<info>user {$username} already present</info>"); continue; }
            $userId = Db::table('users')->insertGetId([
                'username'             => $username,
                'password_hash'        => $tempHash,
                'display_name'         => $display,
                'status'               => 'password_expired',
                'must_change_password' => 1,
                'created_at'           => date('Y-m-d H:i:s'),
                'updated_at'           => date('Y-m-d H:i:s'),
                'password_changed_at'  => date('Y-m-d H:i:s'),
            ]);
            Db::table('password_history')->insert(['user_id' => $userId, 'password_hash' => $tempHash]);
            $roleId = (int)Db::table('roles')->where('key', $roleKey)->value('id');
            if ($roleId) Db::table('user_roles')->insert(['user_id' => $userId, 'role_id' => $roleId]);
            // Admin gets global scope; others scoped to HQ
            if ($isAdmin) {
                Db::table('user_scope_assignments')->insert(['user_id' => $userId, 'is_global' => 1]);
            } elseif ($hqId) {
                Db::table('user_scope_assignments')->insert(['user_id' => $userId, 'location_id' => $hqId]);
            }
            $out->writeln("<info>user {$username} created (initial pwd: Admin!Pass#2026)</info>");
        }
    }
}
