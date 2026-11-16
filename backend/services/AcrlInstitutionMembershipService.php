<?php

namespace app\services;

use Yii;

/** Reads and changes institution membership and its assignment eligibility. */
class AcrlInstitutionMembershipService
{
    const ENABLED_INSTITUTIONS = ['SC'];

    const INSTITUTIONS = [
        'AC' => 'Amherst College',
        'HC' => 'Hampshire College',
        'MH' => 'Mount Holyoke College',
        'SC' => 'Smith College',
        'UM' => 'University of Massachusetts Amherst',
    ];

    private $db;

    public function __construct($db = null)
    {
        $this->db = $db ?: Yii::$app->db;
    }

    public function isAppAdmin(int $userId): bool
    {
        return (bool)$this->db->createCommand(
            "SELECT COUNT(*) FROM users WHERE id = :id AND role = 'admin' AND is_approved = 1",
            [':id' => $userId]
        )->queryScalar();
    }

    public function role(int $userId, string $institution): ?string
    {
        $row = $this->db->createCommand(
            'SELECT m.role FROM acrl_institution_members m INNER JOIN users u ON u.id = m.user_id'
                . ' WHERE m.user_id = :id AND m.institution_code = :institution AND u.is_approved = 1',
            [':id' => $userId, ':institution' => $institution]
        )->queryScalar();
        return $row === false ? null : (string)$row;
    }

    public function canManage(int $userId, string $institution): bool
    {
        return $this->isAppAdmin($userId) || $this->role($userId, $institution) === 'admin';
    }

    public function canManageAny(int $userId): bool
    {
        return $this->isAppAdmin($userId) || (bool)$this->db->createCommand(
            "SELECT COUNT(*) FROM acrl_institution_members m INNER JOIN users u ON u.id = m.user_id"
                . " WHERE m.user_id = :id AND m.role = 'admin' AND u.is_approved = 1",
            [':id' => $userId]
        )->queryScalar();
    }

    public function isMember(int $userId, string $institution): bool
    {
        return $this->isAppAdmin($userId) || $this->role($userId, $institution) !== null;
    }

    public function lockAssignmentEligibility(int $userId, string $institution): bool
    {
        $suffix = $this->db->driverName === 'sqlite' ? '' : ' LOCK IN SHARE MODE';
        $user = $this->db->createCommand(
            'SELECT id, role, is_approved FROM users WHERE id = :id' . $suffix,
            [':id' => $userId]
        )->queryOne();
        if (!$user || (int)$user['is_approved'] !== 1) {
            return false;
        }
        if ((string)$user['role'] === 'admin') {
            return true;
        }
        $member = $this->db->createCommand(
            'SELECT user_id FROM acrl_institution_members WHERE user_id = :id AND institution_code = :institution'
                . $suffix,
            [':id' => $userId, ':institution' => $institution]
        )->queryScalar();
        return $member !== false && $member !== null;
    }

    public function institutionsForUser(int $userId): array
    {
        $result = [];
        $isAdmin = $this->isAppAdmin($userId);
        foreach (self::ENABLED_INSTITUTIONS as $institution) {
            $role = $isAdmin ? 'admin' : $this->role($userId, $institution);
            if ($role !== null) {
                $result[] = ['code' => $institution, 'name' => self::INSTITUTIONS[$institution], 'role' => $role];
            }
        }
        return $result;
    }

    public function members(string $institution): array
    {
        return $this->db->createCommand(
            'SELECT u.id, u.first_name, u.last_name, u.username, u.email, m.role'
                . ' FROM acrl_institution_members m INNER JOIN users u ON u.id = m.user_id'
                . ' WHERE m.institution_code = :institution ORDER BY u.last_name, u.first_name, u.username',
            [':institution' => $institution]
        )->queryAll();
    }

    public function candidates(int $actorId, string $institution, string $query): array
    {
        $params = [':institution' => $institution, ':query' => '%' . trim($query) . '%'];
        $domain = AcrlInstitutionIdentityService::domainForCollege($institution);
        $domainRule = $this->isAppAdmin($actorId) ? '' : ' AND LOWER(u.email) LIKE :domain';
        if ($domainRule !== '') $params[':domain'] = '%@' . $domain;
        $rows = $this->db->createCommand(
            'SELECT u.id, u.first_name, u.last_name, u.username, u.email FROM users u'
                . ' WHERE u.is_approved = 1 AND (u.first_name LIKE :query OR u.last_name LIKE :query OR u.username LIKE :query)'
                . $domainRule
                . ' AND NOT EXISTS (SELECT 1 FROM acrl_institution_members m WHERE m.user_id = u.id AND m.institution_code = :institution)'
                . ' ORDER BY u.last_name, u.first_name, u.username LIMIT 25',
            $params
        )->queryAll();
        return $rows;
    }

    public function add(int $actorId, string $institution, int $userId, string $role): void
    {
        $this->validateRole($role);
        $this->requireManager($actorId, $institution);
        $transaction = $this->db->beginTransaction();
        try {
            $user = $this->db->createCommand(
                'SELECT id, email, is_approved FROM users WHERE id = :id'
                    . ($this->db->driverName === 'sqlite' ? '' : ' FOR UPDATE'), [':id' => $userId]
            )->queryOne();
            if (!$user || (int)$user['is_approved'] !== 1) throw new \DomainException('Only approved users can be added.');
            if (!$this->isAppAdmin($actorId)
                && AcrlInstitutionIdentityService::collegeForEmail($user['email'] === null ? null : (string)$user['email']) !== $institution) {
                throw new \DomainException('You can only add users from your college.');
            }
            $this->db->createCommand()->insert('acrl_institution_members', [
                'user_id' => $userId,
                'institution_code' => $institution,
                'role' => $role,
                'granted_by' => (string)$actorId,
                'granted_at' => date('Y-m-d H:i:s'),
            ])->execute();
            $transaction->commit();
        } catch (\Throwable $error) {
            if ($transaction->isActive) $transaction->rollBack();
            throw $error;
        }
    }

    public function update(int $actorId, string $institution, int $userId, string $role): void
    {
        $this->validateRole($role);
        $this->requireManager($actorId, $institution);
        $count = $this->db->createCommand()->update('acrl_institution_members', ['role' => $role], [
            'user_id' => $userId, 'institution_code' => $institution,
        ])->execute();
        if (!$count && !(int)$this->db->createCommand(
            'SELECT COUNT(*) FROM acrl_institution_members WHERE user_id = :id AND institution_code = :institution',
            [':id' => $userId, ':institution' => $institution]
        )->queryScalar()) throw new \DomainException('The member was not found.');
    }

    public function remove(int $actorId, string $institution, int $userId): void
    {
        $this->requireManager($actorId, $institution);
        $transaction = $this->db->beginTransaction();
        try {
            $suffix = $this->db->driverName === 'sqlite' ? '' : ' FOR UPDATE';
            $member = $this->db->createCommand(
                'SELECT user_id FROM acrl_institution_members WHERE user_id = :id AND institution_code = :institution' . $suffix,
                [':id' => $userId, ':institution' => $institution]
            )->queryScalar();
            if ($member === false || $member === null) throw new \DomainException('The member was not found.');
            $this->db->createCommand()->delete('acrl_institution_members', ['user_id' => $userId, 'institution_code' => $institution])->execute();
            $this->db->createCommand()->delete('acrl_section_assignments', ['user_id' => $userId, 'institution_code' => $institution])->execute();
            $this->db->createCommand()->delete('acrl_question_assignments', ['user_id' => $userId, 'institution_code' => $institution])->execute();
            $transaction->commit();
        } catch (\Throwable $error) {
            if ($transaction->isActive) $transaction->rollBack();
            throw $error;
        }
    }

    private function requireManager(int $actorId, string $institution): void
    {
        if (!array_key_exists($institution, self::INSTITUTIONS) || !$this->canManage($actorId, $institution)) {
            throw new \DomainException('not_a_member', 403);
        }
    }

    private function validateRole(string $role): void
    {
        if (!in_array($role, ['member', 'admin'], true)) throw new \InvalidArgumentException('Role must be member or admin.');
    }
}
