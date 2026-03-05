<?php

namespace Pterodactyl\Http\Controllers\Api\Client\Servers;

use Illuminate\Http\Request;
use Pterodactyl\Models\Server;
use Illuminate\Http\JsonResponse;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Models\Permission;
use Illuminate\Support\Facades\Log;
use Pterodactyl\Repositories\Eloquent\SubuserRepository;
use Pterodactyl\Services\Subusers\SubuserCreationService;
use Pterodactyl\Services\Subusers\SubuserFileAccessService;
use Pterodactyl\Transformers\Api\Client\SubuserTransformer;
use Pterodactyl\Repositories\Wings\DaemonRevocationRepository;
use Pterodactyl\Http\Controllers\Api\Client\ClientApiController;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Http\Requests\Api\Client\Servers\Subusers\GetSubuserRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\Subusers\StoreSubuserRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\Subusers\DeleteSubuserRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\Subusers\UpdateSubuserRequest;

class SubuserController extends ClientApiController
{
    /**
     * SubuserController constructor.
     */
    public function __construct(
        private SubuserRepository $repository,
        private SubuserCreationService $creationService,
        private SubuserFileAccessService $fileAccessService,
        private DaemonRevocationRepository $revocationRepository,
    ) {
        parent::__construct();
    }

    /**
     * Return the users associated with this server instance.
     */
    public function index(GetSubuserRequest $request, Server $server): array
    {
        return $this->fractal->collection($server->subusers)
            ->transformWith($this->getTransformer(SubuserTransformer::class))
            ->toArray();
    }

    /**
     * Returns a single subuser associated with this server instance.
     */
    public function view(GetSubuserRequest $request): array
    {
        $subuser = $request->attributes->get('subuser');

        return $this->fractal->item($subuser)
            ->transformWith($this->getTransformer(SubuserTransformer::class))
            ->toArray();
    }

    /**
     * Create a new subuser for the given server.
     *
     * @throws \Pterodactyl\Exceptions\Model\DataValidationException
     * @throws \Pterodactyl\Exceptions\Service\Subuser\ServerSubuserExistsException
     * @throws \Pterodactyl\Exceptions\Service\Subuser\UserIsServerOwnerException
     * @throws \Throwable
     */
    public function store(StoreSubuserRequest $request, Server $server): array
    {
        $permissions = $this->getDefaultPermissions($request);
        $fileAccess = $this->getDefaultFileAccess($request, $permissions);

        $response = $this->creationService->handle(
            $server,
            $request->input('email'),
            $permissions,
            $fileAccess
        );

        Activity::event('server:subuser.create')
            ->subject($response->user)
            ->property([
                'email' => $request->input('email'),
                'permissions' => $permissions,
                'file_access' => $fileAccess,
            ])
            ->log();

        return $this->fractal->item($response)
            ->transformWith($this->getTransformer(SubuserTransformer::class))
            ->toArray();
    }

    /**
     * Update a given subuser in the system for the server.
     *
     * @throws \Pterodactyl\Exceptions\Model\DataValidationException
     * @throws \Pterodactyl\Exceptions\Repository\RecordNotFoundException
     */
    public function update(UpdateSubuserRequest $request, Server $server): array
    {
        /** @var \Pterodactyl\Models\Subuser $subuser */
        $subuser = $request->attributes->get('subuser');

        $permissions = $this->getDefaultPermissions($request);
        $fileAccess = $this->getDefaultFileAccess($request, $permissions);
        $currentPermissions = $subuser->permissions;
        sort($currentPermissions);
        $currentFileAccess = $this->sortedFileAccess($subuser->file_access ?? []);

        $permissionsChanged = $permissions !== $currentPermissions;
        $fileAccessChanged = $fileAccess !== $currentFileAccess;

        $log = Activity::event('server:subuser.update')
            ->subject($subuser->user)
            ->property([
                'email' => $subuser->user->email,
                'old' => $currentPermissions,
                'new' => $permissions,
                'old_file_access' => $currentFileAccess,
                'new_file_access' => $fileAccess,
                'revoked' => $permissionsChanged,
            ]);

        if ($permissionsChanged || $fileAccessChanged) {
            $log->transaction(function ($instance) use ($subuser, $server, $permissions, $fileAccess, $permissionsChanged) {
                $this->repository->update($subuser->id, [
                    'permissions' => $permissions,
                    'file_access' => $fileAccess,
                ]);

                if (!$permissionsChanged) {
                    $instance->property('revoked', false);

                    return;
                }

                try {
                    $this->revocationRepository->setNode($server->node)->deauthorize(
                        $subuser->user->uuid,
                        [$server->uuid],
                    );
                } catch (DaemonConnectionException $exception) {
                    // Don't block this request if we can't connect to the Wings instance. Chances are it is
                    // offline and the token will be invalid once Wings boots back.
                    Log::warning($exception, ['user_id' => $subuser->user_id, 'server_id' => $server->id]);

                    $instance->property('revoked', false);
                }
            });
        }

        $log->reset();

        return $this->fractal->item($subuser->refresh())
            ->transformWith($this->getTransformer(SubuserTransformer::class))
            ->toArray();
    }

    /**
     * Removes a subusers from a server's assignment.
     */
    public function delete(DeleteSubuserRequest $request, Server $server): JsonResponse
    {
        /** @var \Pterodactyl\Models\Subuser $subuser */
        $subuser = $request->attributes->get('subuser');

        $log = Activity::event('server:subuser.delete')
            ->subject($subuser->user)
            ->property('email', $subuser->user->email)
            ->property('revoked', true);

        $log->transaction(function ($instance) use ($server, $subuser) {
            $subuser->delete();

            try {
                $this->revocationRepository->setNode($server->node)->deauthorize(
                    $subuser->user->uuid,
                    [$server->uuid],
                );
            } catch (DaemonConnectionException $exception) {
                // Don't block this request if we can't connect to the Wings instance.
                Log::warning($exception, ['user_id' => $subuser->user_id, 'server_id' => $server->id]);

                $instance->property('revoked', false);
            }
        });

        return new JsonResponse([], JsonResponse::HTTP_NO_CONTENT);
    }

    /**
     * Returns the default permissions for subusers and parses out any permissions
     * that were passed that do not also exist in the internally tracked list of
     * permissions.
     */
    protected function getDefaultPermissions(Request $request): array
    {
        $allowed = Permission::permissions()
            ->map(function ($value, $prefix) {
                return array_map(function ($value) use ($prefix) {
                    return "$prefix.$value";
                }, array_keys($value['keys']));
            })
            ->flatten()
            ->all();

        $cleaned = array_intersect($request->input('permissions') ?? [], $allowed);

        $permissions = array_unique(array_merge($cleaned, [Permission::ACTION_WEBSOCKET_CONNECT]));
        sort($permissions);

        return $permissions;
    }

    /**
     * Returns the configured file access rules for a subuser.
     *
     * @param string[] $permissions
     *
     * @return array<string, array{allow: string[], deny: string[]}>
     */
    protected function getDefaultFileAccess(Request $request, array $permissions): array
    {
        $fileAccess = $request->input('file_access');
        if (!is_array($fileAccess)) {
            return [];
        }

        return $this->sortedFileAccess($this->fileAccessService->sanitize($fileAccess, $permissions));
    }

    /**
     * @param array<string, mixed> $fileAccess
     *
     * @return array<string, array{allow: string[], deny: string[]}>
     */
    protected function sortedFileAccess(array $fileAccess): array
    {
        $sorted = [];

        foreach ($fileAccess as $action => $rules) {
            if (!is_array($rules)) {
                continue;
            }

            $allow = is_array($rules['allow'] ?? null) ? $rules['allow'] : [];
            $deny = is_array($rules['deny'] ?? null) ? $rules['deny'] : [];

            $allow = array_values(array_filter($allow, fn ($value) => is_string($value) && $value !== ''));
            $deny = array_values(array_filter($deny, fn ($value) => is_string($value) && $value !== ''));

            sort($allow);
            sort($deny);

            if (empty($allow) && empty($deny)) {
                continue;
            }

            $sorted[$action] = [
                'allow' => $allow,
                'deny' => $deny,
            ];
        }

        ksort($sorted);

        return $sorted;
    }
}
