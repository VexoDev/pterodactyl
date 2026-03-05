<?php

namespace Pterodactyl\Services\Subusers;

use Pterodactyl\Models\User;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\Subuser;
use Pterodactyl\Models\Permission;
use Pterodactyl\Exceptions\Http\HttpForbiddenException;

class SubuserFileAccessService
{
    public const ACTION_READ = 'read';
    public const ACTION_READ_CONTENT = 'read-content';
    public const ACTION_CREATE = 'create';
    public const ACTION_UPDATE = 'update';
    public const ACTION_DELETE = 'delete';
    public const ACTION_ARCHIVE = 'archive';

    protected const RULE_ALLOW = 'allow';
    protected const RULE_DENY = 'deny';

    protected const PERMISSION_ACTION_MAP = [
        Permission::ACTION_FILE_READ => self::ACTION_READ,
        Permission::ACTION_FILE_READ_CONTENT => self::ACTION_READ_CONTENT,
        Permission::ACTION_FILE_CREATE => self::ACTION_CREATE,
        Permission::ACTION_FILE_UPDATE => self::ACTION_UPDATE,
        Permission::ACTION_FILE_DELETE => self::ACTION_DELETE,
        Permission::ACTION_FILE_ARCHIVE => self::ACTION_ARCHIVE,
    ];

    /**
     * @return string[]
     */
    public static function actionKeys(): array
    {
        return array_values(self::PERMISSION_ACTION_MAP);
    }

    /**
     * @param array<string, mixed> $rules
     * @param string[] $permissions
     *
     * @return array<string, array{allow: string[], deny: string[]}>
     */
    public function sanitize(array $rules, array $permissions): array
    {
        $allowedActions = [];
        foreach ($permissions as $permission) {
            $action = self::PERMISSION_ACTION_MAP[$permission] ?? null;
            if (!is_null($action)) {
                $allowedActions[$action] = true;
            }
        }

        $sanitized = [];
        foreach (self::actionKeys() as $action) {
            if (!isset($allowedActions[$action])) {
                continue;
            }

            $raw = $rules[$action] ?? [];
            if (!is_array($raw)) {
                continue;
            }

            $allow = $this->sanitizeExpressions($raw[self::RULE_ALLOW] ?? []);
            $deny = $this->sanitizeExpressions($raw[self::RULE_DENY] ?? []);

            if (empty($allow) && empty($deny)) {
                continue;
            }

            $sanitized[$action] = [
                self::RULE_ALLOW => $allow,
                self::RULE_DENY => $deny,
            ];
        }

        return $sanitized;
    }

    /**
     * @param array<int, string|array{path: string, directory?: bool}> $paths
     *
     * @throws HttpForbiddenException
     */
    public function assertUserCanAccessPaths(Server $server, User $user, string $permission, array $paths): void
    {
        if ($user->root_admin || $server->owner_id === $user->id) {
            return;
        }

        /** @var Subuser|null $subuser */
        $subuser = $server->relationLoaded('subusers')
            ? $server->subusers->firstWhere('user_id', $user->id)
            : $server->subusers()->where('user_id', $user->id)->first();

        if (is_null($subuser)) {
            throw new HttpForbiddenException('You do not have permission to access one or more of the selected files or folders.');
        }

        if (!$this->canAccessPaths($subuser, $permission, $paths)) {
            throw new HttpForbiddenException('You do not have permission to access one or more of the selected files or folders.');
        }
    }

    /**
     * @param array<int, string|array{path: string, directory?: bool}> $paths
     */
    public function canAccessPaths(Subuser $subuser, string $permission, array $paths): bool
    {
        $action = self::PERMISSION_ACTION_MAP[$permission] ?? null;
        if (is_null($action)) {
            return true;
        }

        $fileAccess = $subuser->file_access ?? [];
        if (!isset($fileAccess[$action]) || !is_array($fileAccess[$action])) {
            return true;
        }

        $rules = $fileAccess[$action];
        foreach ($paths as $path) {
            $directory = is_array($path) ? (bool) ($path['directory'] ?? false) : false;
            $value = is_array($path) ? ($path['path'] ?? '') : $path;
            $normalizedPath = $this->normalizePath($value, $directory);
            if (!$this->isPathAllowed($rules, $normalizedPath, $directory)) {
                return false;
            }
        }

        return true;
    }

    public function joinPath(?string $root, string $path, bool $directory = false): string
    {
        $path = trim($path);
        if ($path === '') {
            return $this->normalizePath($root ?? '/', $directory);
        }

        $path = str_replace('\\', '/', $path);
        if (str_starts_with($path, '/')) {
            return $this->normalizePath($path, $directory);
        }

        $root = rtrim($this->normalizePath($root ?? '/', true), '/');

        return $this->normalizePath($root . '/' . ltrim($path, '/'), $directory);
    }

    public function normalizePath(string $path, bool $directory = false): string
    {
        $path = rawurldecode(trim($path));
        $path = str_replace('\\', '/', $path);

        if ($path === '') {
            $path = '/';
        }

        if (!str_starts_with($path, '/')) {
            $path = '/' . $path;
        }

        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        $normalized = '/' . implode('/', $segments);
        if ($normalized === '') {
            $normalized = '/';
        }

        if ($directory && $normalized !== '/') {
            $normalized .= '/';
        }

        return $normalized;
    }

    public static function isValidExpression(string $expression): bool
    {
        $expression = trim($expression);
        if ($expression === '') {
            return false;
        }

        return @preg_match(self::toRegexPattern($expression), '/') !== false;
    }

    /**
     * @param mixed $values
     *
     * @return string[]
     */
    protected function sanitizeExpressions($values): array
    {
        if (!is_array($values)) {
            return [];
        }

        $sanitized = [];
        foreach ($values as $value) {
            if (!is_string($value)) {
                continue;
            }

            $value = trim($value);
            if ($value === '' || !self::isValidExpression($value)) {
                continue;
            }

            $sanitized[] = $value;
        }

        return array_values(array_unique($sanitized));
    }

    /**
     * @param array<string, mixed> $rules
     */
    protected function isPathAllowed(array $rules, string $path, bool $directory): bool
    {
        $allow = is_array($rules[self::RULE_ALLOW] ?? null) ? $rules[self::RULE_ALLOW] : [];
        $deny = is_array($rules[self::RULE_DENY] ?? null) ? $rules[self::RULE_DENY] : [];

        $candidates = [$path];
        if (!$directory && $path !== '/') {
            $candidates[] = rtrim($path, '/') . '/';
        }

        foreach ($deny as $expression) {
            foreach ($candidates as $candidate) {
                if ($this->matches($expression, $candidate)) {
                    return false;
                }
            }
        }

        if (empty($allow)) {
            return true;
        }

        foreach ($allow as $expression) {
            foreach ($candidates as $candidate) {
                if ($this->matches($expression, $candidate)) {
                    return true;
                }
            }
        }

        return false;
    }

    protected function matches(string $expression, string $path): bool
    {
        return @preg_match(self::toRegexPattern($expression), $path) === 1;
    }

    protected static function toRegexPattern(string $expression): string
    {
        return '#' . str_replace('#', '\#', $expression) . '#';
    }
}
