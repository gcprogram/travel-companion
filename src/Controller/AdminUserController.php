<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\DayEntryRepository;
use App\Repository\JobRepository;
use App\Repository\PhotoRepository;
use App\Repository\TrackRepository;
use App\Repository\TripRepository;
use App\Repository\UserRepository;
use App\Repository\VideoRepository;
use App\Service\StorageQuotaService;
use App\Service\UserRole;
use App\Support\Flash;
use App\Support\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * Admin-only user management: the overview list with per-user stats, and
 * the mutating actions (role, active state, quota override, delete,
 * ownership transfer). Gated entirely by RequireAdmin at the route-group
 * level (see config/routes.php) - every method here assumes the current
 * request's user is already a confirmed admin.
 */
final class AdminUserController
{
    private const CREATABLE_ROLES = [UserRole::USER, UserRole::AI_USER, UserRole::MANAGER, UserRole::ADMIN];

    public function __construct(
        private readonly View $view,
        private readonly UserRepository $users,
        private readonly TripRepository $trips,
        private readonly DayEntryRepository $entries,
        private readonly PhotoRepository $photos,
        private readonly VideoRepository $videos,
        private readonly TrackRepository $tracks,
        private readonly StorageQuotaService $quota,
        private readonly UserRole $roles,
        private readonly JobRepository $jobs,
        private readonly Flash $flash,
    ) {
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $currentUserId = (int) $request->getAttribute('user')['id'];

        $rows = array_map(function (array $user): array {
            $userId = (int) $user['id'];
            return [
                'user' => $user,
                'status' => $this->statusFor($user),
                'tripCount' => $this->trips->countByUser($userId),
                'entryCount' => $this->entries->countByUser($userId),
                'photoCount' => $this->photos->countByUser($userId),
                'videoCount' => $this->videos->countByUser($userId),
                'trackCount' => $this->tracks->countByUser($userId),
                'storageUsedBytes' => $this->quota->usedBytes($userId),
                'storageQuotaBytes' => $this->roles->storageQuotaBytes($user),
            ];
        }, $this->users->findAll());

        return $this->view->render($response, 'admin/users', [
            'rows' => $rows,
            'roles' => UserRole::ALL,
            'currentUserId' => $currentUserId,
        ]);
    }

    public function showCreate(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->view->render($response, 'admin/user_new', ['roles' => self::CREATABLE_ROLES]);
    }

    public function store(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $email = trim((string) ($body['email'] ?? ''));
        $name = trim((string) ($body['name'] ?? ''));
        $password = (string) ($body['password'] ?? '');
        $role = (string) ($body['role'] ?? UserRole::USER);

        $errors = [];
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = t('validation.email_invalid');
        }
        if (mb_strlen($name) < 2) {
            $errors[] = t('validation.name_required');
        }
        if (mb_strlen($password) < 10) {
            $errors[] = t('validation.password_min_length', ['min' => 10]);
        }
        if (!in_array($role, self::CREATABLE_ROLES, true)) {
            $role = UserRole::USER;
        }
        if ($errors === [] && $this->users->findByEmail($email) !== null) {
            $errors[] = t('admin.user_email_taken');
        }

        if ($errors !== []) {
            return $this->view->render($response, 'admin/user_new', [
                'roles' => self::CREATABLE_ROLES,
                'errors' => $errors,
                'old' => ['email' => $email, 'name' => $name, 'role' => $role],
            ], status: 422);
        }

        // Admin-created accounts skip confirmation/approval entirely - the
        // admin is vouching for the address directly.
        $this->users->create($email, $name, password_hash($password, PASSWORD_DEFAULT), $role, active: true);

        $this->flash->add('success', t('admin.user_created'));
        return $this->redirect($response, '/admin/users');
    }

    public function setRole(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request, (int) $args['id']);
        $role = (string) ((array) $request->getParsedBody())['role'] ?? '';

        if (!$this->roles->isValid($role)) {
            $this->flash->add('error', t('admin.invalid_role'));
            return $this->redirect($response, '/admin/users');
        }
        if ($this->isSelf($request, (int) $user['id']) && $role !== UserRole::ADMIN) {
            $this->flash->add('error', t('admin.cannot_demote_self'));
            return $this->redirect($response, '/admin/users');
        }

        $this->users->setRole((int) $user['id'], $role);
        $this->flash->add('success', t('admin.role_updated'));
        return $this->redirect($response, '/admin/users');
    }

    public function approve(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request, (int) $args['id']);
        $this->users->markApprovedAndActive((int) $user['id']);
        $this->flash->add('success', t('admin.user_approved'));
        return $this->redirect($response, '/admin/users');
    }

    public function setActive(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request, (int) $args['id']);
        $active = ((array) $request->getParsedBody())['active'] ?? '0';

        if ($active !== '1' && $this->isSelf($request, (int) $user['id'])) {
            $this->flash->add('error', t('admin.cannot_deactivate_self'));
            return $this->redirect($response, '/admin/users');
        }

        $this->users->setActive((int) $user['id'], $active === '1');
        $this->flash->add('success', t('admin.status_updated'));
        return $this->redirect($response, '/admin/users');
    }

    public function setQuota(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request, (int) $args['id']);
        $raw = trim((string) (((array) $request->getParsedBody())['bytes'] ?? ''));

        $bytes = null;
        if ($raw !== '') {
            if (!is_numeric($raw) || (float) $raw < 0) {
                $this->flash->add('error', t('admin.invalid_quota'));
                return $this->redirect($response, '/admin/users');
            }
            $bytes = (int) round((float) $raw * 1024 * 1024); // form takes MB
        }

        $this->users->setStorageQuotaOverride((int) $user['id'], $bytes);
        $this->flash->add('success', t('admin.quota_updated'));
        return $this->redirect($response, '/admin/users');
    }

    public function transfer(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $source = $this->requireUser($request, (int) $args['id']);
        $targetId = (int) (((array) $request->getParsedBody())['target_user_id'] ?? 0);
        $target = $this->users->findById($targetId);

        if ($target === null || $targetId === (int) $source['id']) {
            $this->flash->add('error', t('admin.invalid_transfer_target'));
            return $this->redirect($response, '/admin/users');
        }

        $count = $this->trips->transferOwnership((int) $source['id'], $targetId);
        $this->flash->add('success', t('admin.trips_transferred', ['count' => $count]));
        return $this->redirect($response, '/admin/users');
    }

    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $this->requireUser($request, (int) $args['id']);

        if ($this->isSelf($request, (int) $user['id'])) {
            $this->flash->add('error', t('admin.cannot_delete_self'));
            return $this->redirect($response, '/admin/users');
        }

        // Deactivate immediately (locks the account out right away); the
        // actual file/row cleanup runs as a job since it can take longer
        // than the request time limit for a user with a lot of media.
        $this->users->setActive((int) $user['id'], false);
        $this->jobs->dispatch('user.delete', ['user_id' => (int) $user['id']]);

        $this->flash->add('success', t('admin.user_delete_queued'));
        return $this->redirect($response, '/admin/users');
    }

    private function isSelf(ServerRequestInterface $request, int $userId): bool
    {
        return (int) $request->getAttribute('user')['id'] === $userId;
    }

    /**
     * @return array<string, mixed>
     */
    private function requireUser(ServerRequestInterface $request, int $userId): array
    {
        $user = $this->users->findById($userId);
        if ($user === null) {
            throw new HttpNotFoundException($request);
        }
        return $user;
    }

    /**
     * @param array<string, mixed> $user
     */
    private function statusFor(array $user): string
    {
        if ((bool) $user['is_active']) {
            return 'active';
        }
        if ($user['email_confirmed_at'] === null) {
            return 'pending_confirmation';
        }
        if ($user['approved_at'] === null) {
            return 'pending_approval';
        }
        return 'inactive';
    }

    private function redirect(ResponseInterface $response, string $to): ResponseInterface
    {
        return $response->withHeader('Location', $to)->withStatus(302);
    }
}
