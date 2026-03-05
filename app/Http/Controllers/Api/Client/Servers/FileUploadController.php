<?php

namespace Pterodactyl\Http\Controllers\Api\Client\Servers;

use Carbon\CarbonImmutable;
use Pterodactyl\Models\User;
use Pterodactyl\Models\Server;
use Illuminate\Http\JsonResponse;
use Pterodactyl\Services\Nodes\NodeJWTService;
use Pterodactyl\Models\Permission;
use Pterodactyl\Services\Subusers\SubuserFileAccessService;
use Pterodactyl\Http\Controllers\Api\Client\ClientApiController;
use Pterodactyl\Http\Requests\Api\Client\Servers\Files\UploadFileRequest;

class FileUploadController extends ClientApiController
{
    /**
     * FileUploadController constructor.
     */
    public function __construct(
        private NodeJWTService $jwtService,
        private SubuserFileAccessService $fileAccessService,
    ) {
        parent::__construct();
    }

    /**
     * Returns an url where files can be uploaded to.
     */
    public function __invoke(UploadFileRequest $request, Server $server): JsonResponse
    {
        $directory = $request->input('directory') ?? '/';
        $this->fileAccessService->assertUserCanAccessPaths($server, $request->user(), Permission::ACTION_FILE_CREATE, [[
            'path' => $directory,
            'directory' => true,
        ]]);

        return new JsonResponse([
            'object' => 'signed_url',
            'attributes' => [
                'url' => $this->getUploadUrl($server, $request->user(), $directory),
            ],
        ]);
    }

    /**
     * Returns an url where files can be uploaded to.
     */
    protected function getUploadUrl(Server $server, User $user, string $directory): string
    {
        $token = $this->jwtService
            ->setExpiresAt(CarbonImmutable::now()->addMinutes(15))
            ->setUser($user)
            ->setClaims([
                'server_uuid' => $server->uuid,
                'directory' => $directory,
            ])
            ->handle($server->node, $user->id . $server->uuid);

        return sprintf(
            '%s/upload/file?token=%s',
            $server->node->getConnectionAddress(),
            $token->toString()
        );
    }
}
